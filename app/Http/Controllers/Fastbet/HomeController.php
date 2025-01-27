<?php

namespace App\Http\Controllers\Fastbet;

use Carbon\Carbon;
use App\Helpers\Bot;
use App\Models\Brand;
use App\Helpers\LineApi;
use App\Models\Customer;
use App\Models\Promotion;
use App\Helpers\FastbetApi;
use Illuminate\Http\Request;
use App\Models\PromotionCost;
use App\Helpers\FastbetBotApi;
use App\Models\CustomerDeposit;
use App\Models\BankAccountHistory;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    //
    public function index($brand) {
    
        $brand = Brand::whereSubdomain($brand)->first();

        $customer = Customer::find(Auth::guard('customer')->user()->id);

        $promotion_queries = Promotion::whereBrandId($brand->id)->get();

        

        $credit = Bot::creditFastbet($brand,$customer);

        $customer->update([
            'credit' => $credit['data']['credit']
        ]);

        $promotions = collect([]);

        foreach($promotion_queries as $promotion_query) {

            if ($promotion_query->type_promotion == 1) {

                $customer_deposits = CustomerDeposit::whereCustomerId($customer->id)->wherePromotionId($promotion_query->id)->whereBetween('created_at', [date('Y-m-d 00:00:00'),date('Y-m-d 23:59:59')])->get();

                if($customer_deposits->count() == 0) {

                    $promotions->push($promotion_query);

                }

            } else if($promotion_query->type_promotion == 3) {

                $customer_deposits = CustomerDeposit::whereCustomerId($customer->id)->wherePromotionId($promotion_query->id)->get();

                if($customer_deposits->count() == 0) {

                    $promotions->push($promotion_query);
                    
                }

            } else if($promotion_query->type_promotion == 2) {

                $promotions->push($promotion_query); 
 
             }

        }

        $balance = $customer->credit;

        return view('fastbet.members.home',\compact('brand','balance','promotions'));

    }

    public function history($brand) {
    
        $brand = Brand::whereSubdomain($brand)->first();

        $customer = Customer::find(Auth::guard('customer')->user()->id);

        $customer_deposits = $customer->deposits->sortByDesc('created_at')->take(5);

        $customer_withdraws = $customer->withdraws->sortByDesc('created_at')->take(5);

        $histories = $customer_deposits->concat($customer_withdraws);
        
        $balance = $customer->credit;

        return view('fastbet.members.history', compact('customer','brand','histories'));

    }

    public function checkAuth(Request $request) {

        $input = $request->all();

        $brand = Brand::find($input['brand_id']);

        $customer = Customer::whereLineUserId($input['line_user_id'])->whereBrandId($brand->id)->first();
        if(!$customer) {

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://api.line.me/v2/bot/user/".$input['line_user_id']."/richmenu/".$brand->line_menu_register,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer '.$brand->line_token.'"
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);

            return response()->json([
                'code' => 404,
                'data' => [],
            ]);

        } else {

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://api.line.me/v2/bot/user/".$input['line_user_id']."/richmenu/".$brand->line_menu_member,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_HTTPHEADER => array(
                    "Authorization: Bearer '.$brand->line_token.'"
                ),
            ));

            $response = curl_exec($curl);

            curl_close($curl);

            return response()->json([
                'code' => 200,
                'data' => $customer
            ]);

        }

    }

    public function welcome($brand) {

        $brand = Brand::whereSubdomain($brand)->first();

        $customer = Customer::find(Auth::guard('customer')->user()->id);

        return view('fastbet.members.welcome', compact('brand','customer'));

    }

    public function updatePromotion(Request $request) {

        $input = $request->all();

        DB::beginTransaction();

        Customer::find($input['customer_id'])->update([
            'promotion_id' => $input['promotion_id']
        ]);

        DB::commit();

        return response()->json([
            'status' => true,
        ]);

    }

    

    public function promotion($brand) {
    
        $brand = Brand::whereSubdomain($brand)->first();

        $promotions = Promotion::whereBrandId($brand->id)->get();

        return view('fastbet.members.promotion', compact('promotions','brand'));

    }

    public function connectLine($brand) {

        $brand = Brand::whereSubdomain($brand)->first();

        $customer = Customer::find(Auth::guard('customer')->user()->id);

        return view('fastbet.members.connect-line',compact('brand','customer'));

    }
    
    public function connectLineStore(Request $request) {

        $input = $request->all();

        DB::beginTransaction();

        $customer = Customer::whereUsername($input['username'])->first();

        if(!$customer) {

            return response()->json([
                'status' => false,
                'msg' => 'ไม่พบไอดีนี้ในเกมส์ กรุณาสมัครสมาชิกก่อน ค่ะ'
            ]);

        }
        
        $customer->update([
            'line_user_id' => $input['line_user_id'],
            'img_url' => $input['picture']
        ]);

        $brand = Brand::find($customer->brand_id);

        $rich_menu_id = $brand->line_menu_member;

        $token = $brand->line_token;

        $channel_secret = $brand->line_channel_secret;

        $httpClient = new \LINE\LINEBot\HTTPClient\CurlHTTPClient($token);

        $bot = new \LINE\LINEBot($httpClient, ['channelSecret' => $channel_secret]);

        $response = $bot->linkRichMenu($input['line_user_id'], $rich_menu_id);

        $line_api = new LineApi();
                
        $line_api->token = $brand->line_token;

        $line_api->channel_secret = $brand->line_channel_secret;

        $message1 = "ขอบคุณที่กดปุ่มกระดิ่งแจ้งเตือนกับเรานะคะ \n";

        $message1 .= "Username: ".$customer->username." \n";

        $message1 .= "Password: ".$customer->password_generate." \n";

        $message1 .= "กดเติมเงินได้ที่เมนูเติมเงินเลยนะคะ \n";

        if($brand->game_id == 5) {

            $message1 .= 'ทางเข้าเล่น: https://fastbet98.com/#/';

        }
    
        $push = $line_api->pushMessage($customer->line_user_id, $message1);

        DB::commit();

        return \response()->json([
            'status' => true,
            'msg' => 'เชื่อมต่อกับบัญชี LINE เรียบร้อยขอบคุณค่ะ'
        ]);
        

    }

    public function connectStore(Request $request) {

        $input = $request->all();

        DB::beginTransaction();

        $customer = Customer::whereUsername($input['username'])->first();
        
        $customer->update([
            'line_user_id' => $input['line_user_id'],
            'img_url' => $input['picture']
        ]);

        $brand = Brand::find($customer->brand_id);

        $rich_menu_id = $brand->line_menu_member;

        $token = $brand->line_token;

        $channel_secret = $brand->line_channel_secret;

        $httpClient = new \LINE\LINEBot\HTTPClient\CurlHTTPClient($token);

        $bot = new \LINE\LINEBot($httpClient, ['channelSecret' => $channel_secret]);

        $response = $bot->linkRichMenu($input['line_user_id'], $rich_menu_id);
        
        $line_api = new LineApi();
                
        $line_api->token = $brand->line_token;

        $line_api->channel_secret = $brand->line_channel_secret;

        $message1 = "ขอบคุณที่กดปุ่มกระดิ่งแจ้งเตือนกับเรานะคะ \n";

        $message1 .= "Username: ".$customer->username." \n";

        $message1 .= "Password: ".$customer->password_generate." \n";

        $message1 .= "กดเติมเงินได้ที่เมนูเติมเงินเลยนะคะ \n";

        if($brand->game_id == 5) {

            $message1 .= 'ทางเข้าเล่น: https://fastbet98.com/#/';

        }
    
        $push = $line_api->pushMessage($customer->line_user_id, $message1);

        DB::commit();

    }

    public function invite($brand) {
    
        DB::beginTransaction();

        $brand = Brand::whereSubdomain($brand)->first();

        $customer = Customer::find(Auth::guard('customer')->user()->id);

        if($customer->status_invite == 0) {

            $invite_url = 'https://'.$brand->game->name.'.casinoauto.io/'.$brand->subdomain.'/member/register/'.$customer->id;

            $customer->update([
                'invite_url' => $invite_url,
                'status_invite' => 0,
            ]);

        }

        $promotion = Promotion::whereBrandId($brand->id)->whereTypePromotion(5)->whereTypePromotionInvite(1)->whereStatus(1)->first();

        $total_invite_bonus = 0;

        if($promotion) {

            $customer_invites = Customer::whereBrandId($brand->id)->whereInviteId($customer->id)->where('status_deposit','=',1)->where('invite_bonus','=',0)->where('status_invite','=',0)->get();

            foreach($customer_invites as $customer_invite) {

                $customer_deposit = $customer_invite->deposits->where('status','=',1)->first();

                if($customer_deposit) {

                    if($promotion->type_cost == 1) {

                        $invite_bonus = ($customer_deposit->amount * $promotion->cost) / 100;

                        if($invite_bonus > $promotion->max) {

                            $invite_bonus = $promotion->max;

                        }

                    } else if ($promotion->type_cost == 2) {

                        $invite_bonus = $promotion->cost;
    
                    }

                    $total_invite_bonus += $invite_bonus;

                    $customer_invite->update([
                        'invite_bonus' => $invite_bonus,
                        'status_invite' => 1,
                    ]);

                }

            }

            $promotion_cost_last = PromotionCost::whereCustomerId($customer->id)->wherePromotionId($promotion->id)->orderBy('created_at','desc')->first();

        } else {

            $promotion_cost_last = null;

        }

        $customer_invites = Customer::whereInviteId($customer->id)->orderBy('created_at','desc')->paginate(10);

        $customer_invite_bonus = Customer::whereBrandId($brand->id)->whereInviteId($customer->id)->where('status_invite','=',1)->get();

        DB::commit();

        return view('fastbet.members.invite',compact('brand','customer','customer_invites','promotion','promotion_cost_last','customer_invite_bonus'));

    }

    public function inviteStore(Request $request, $brand) {

        $input = $request->all();

        DB::beginTransaction();

        $brand = Brand::whereSubdomain($brand)->first();

        $customer = Customer::find(Auth::guard('customer')->user()->id);

        $promotion_cost = PromotionCost::whereCustomerId($customer->id)->wherePromotionId($input['promotion_id'])->where('status','=',0)->first();

        if($promotion_cost) {

            return redirect()->back()->withErrors(['คุณติดโปรโมชั่นอื่นอยู่ กรุณาลองใหม่อีกครั้ง']);

        }
        
        $promotion_cost_last = PromotionCost::whereCustomerId($customer->id)
            ->wherePromotionId($input['promotion_id'])
            ->where('created_at',[date('Y-m-d 00:00:00'),date('Y-m-d 23:59:59')])->first();

        if($promotion_cost_last) {

            return redirect()->back()->withErrors(['คุณรับโปรโมชั่นนี้ไปแล้ว ลองใหม่วันพรุ่งนี้ นะคะ']);

        }

        $customer_invites = Customer::whereBrandId($brand->id)->whereInviteId($customer->id)->where('status_invite','=',1)->get();
        
        $amount = $customer_invites->sum('invite_bonus');

        $promotion = Promotion::find($input['promotion_id']);

        if($amount > 0) {

            if($brand->game_id == 1) {

                $response = $this->depositGclub($brand,$customer,$amount);

            } else if ($brand->game_id == 5) {

                $response = $this->depositFastbet($brand,$customer,$amount);

            }

            if($response['status'] == true) {

                PromotionCost::create([
                    'brand_id' => $brand->id,
                    'promotion_id' => $promotion->id,
                    'customer_id' => $customer->id,
                    'username' => $customer->username,
                    'amount' => 0,
                    'bonus' => $amount,
                    'status' => 0,
                ]);

            }

        }

        foreach($customer_invites as $customer_invite) {

            $customer_invite->update([
                'status_invite' => 2,
            ]);

        }

        DB::commit();

        return redirect()->route('fastbet.member', $brand->subdomain);

    }

    public function depositFastbet($brand,$customer,$amount) {

        $fastbet_bot_api_deposit = Bot::depositFastbet($brand,$customer,$amount);

        if($fastbet_bot_api_deposit['status'] === true) {

        } else {

            return \redirect()->back()->withErrors(['API ERROR ลองใหม่อีกครั้งครับ']);    

        }

        return $fastbet_bot_api_deposit;

    }

    public function changePassword(Request $request) {

        $input = $request->all();

        DB::beginTransaction();

        $customer = Customer::find(Auth::guard('customer')->user()->id);

        $brand = Brand::find($customer->brand_id);

        $last = Carbon::parse($customer->last_update_password);

        $now = Carbon::parse(date('Y-m-d H:i:s'));

        $duration = $now->diffInSeconds($last);

        $time = $duration;

        if($duration <= 60) {

            return \redirect()->back()->withErrors(['ลองใหม่อีกครั้งใน 60 วินาที']); 

        } 

        if($customer->password_generate != $input['password_old']) {

            return \redirect()->back()->withErrors(['รหัสผ่านเก่าไม่ถูกต้องนะคะ']); 

        }

        $change_password = Bot::changePasswordFastbet($brand,$customer->username,$input['password']);

        if($change_password['status'] == true) {

            $input['password_generate'] = $input['password'];

            $input['password'] = \bcrypt($input['password']);

            $input['last_update_password'] = date('Y-m-d H:i:s');

            $customer->update($input);

        } else {

            return redirect()->back()->withErrors(['API ERROR TRY AGAIN']);

        }

        DB::commit();

        \Session::flash('alert-success', 'แก้ไขข้อมูลเสร็จแล้ว');

        return redirect()->back();

    }

    public function getCredit() {

        $customer = Customer::find(Auth::guard('customer')->user()->id);

        if($customer) {

            $last = Carbon::parse($customer->last_update_credit);

            $now = Carbon::parse(date('Y-m-d H:i:s'));

            $duration = $now->diffInSeconds($last);

            $time = 30 - $duration;

            if($duration <= 30) {

                return response()->json([
                    'code' => 0,
                    'duration' => $duration,
                    'message' => 'กรุณาลองใหม่อีกครั้งใน '.$time.' วินาที'
                ]);

            }

        }

        $brand = Brand::find($customer->brand_id);

        $response = Bot::creditFastbet($brand,$customer);

        return response()->json($response);

    }

    public function profile($brand) {

        $brand = Brand::whereSubdomain($brand)->first();

        $customer = Customer::find(Auth::guard('customer')->user()->id);

        return view('fastbet.members.profile', compact('brand','customer'));

    }
}
