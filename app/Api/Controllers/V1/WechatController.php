<?php
/**
 *  FileName: WechatController.php
 *  Description :
 *  Author: DC
 *  Date: 2019/6/17
 *  Time: 14:27
 */


namespace App\Api\Controllers\V1;

use App\Api\Controllers\Controller;
use App\Api\Utils\Log;
use App\Api\Utils\Response;
use App\Models\Member;
use App\Models\MemberAccess;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Kernel\Kernel;

class WechatController extends Controller
{
    public $appid = "wxba569217a731ca22";
    public $appsecret = "aef14ef6a0745427bbdd1bf19b291623";

    //根据传入的code获取OPENID，数据库中存在数据自动登录
    public function login(Request $request)
    {
        $code = $request->get("code","");
        $url = "https://api.weixin.qq.com/sns/jscode2session?appid=".$this->appid."&secret=".$this->appsecret."&js_code=".$code."&grant_type=authorization_code";
        $curl = Kernel::curl();
        $curl->ssl = true;
        $curl_result = json_decode($curl->get($url));
        if(empty($curl_result)) {
            return Response::fail("获取session_key及openID时异常，微信内部错误");
        }
        if(isset($curl_result->openid)){
            $model = Member::whereRaw("openid = ? and status = 1 and delete_time = 0", [$curl_result->openid])->first();
            if($model){
                $userInfo = Member::login($model);
                config(["webconfig.userInfo" => $userInfo]);
                list($token, $exp) = Kernel::token()->create($userInfo);
                $result = ["data" => ["token" => $token, "exp" => $exp]];
                Log::create($request);
            }else{
                $result = ["data" => ["openid" => $curl_result->openid]];
            }
            return Response::success($result);
        }else{
            return Response::fail("请求错误，微信内部错误码[".$curl_result->errcode."]");
        }
    }

    //绑定微信账号，完成绑定后自动登录
    public function bind(Request $request)
    {
        DB::beginTransaction();
        try {
            $this->validate($request, ["username" => "required", "password" => "required", "openid" => "required"], [], ["username" => "用户名", "password" => "密码", "openid" => "微信唯一码"]);
            $userInfo = Member::loginByPassword($request);
            Member::updateForData($userInfo["uid"], ["openid" => $request->get("openid", "")]);
            config(["webconfig.userInfo" => $userInfo]);
            list($token, $exp) = Kernel::token()->create($userInfo);
            Log::create($request);
            DB::commit();
            return Response::success(["data" => ["token" => $token, "exp" => $exp]]);
        } catch (Exception $exception) {
            DB::rollBack();
            return Response::fail($exception->getMessage());
        }
    }

    //微信账号注册，注册完成后自动登录
    public function register(Request $request)
    {
        $this->validate($request, [
            'truename' => 'required|unique:member',
            'password' => 'required|string',
            'confirm_password' => 'required|string',
            'phone' => 'required|string|max:30',
            'attence_num' => 'string|max:30',
            'department_id' => 'required|integer',
            'openid'=>'required|string'
        ], [], ["truename" => "真实姓名", "password" => "用户密码", "confirm_password" => "密码确认", "phone" => "手机号", "attence_num" => "考勤号", "department_id" => "部门ID", "openid" => "微信唯一码"]);
        $data = $request->all();
        if($data["password"] != $data["confirm_password"]){
            return Response::fail("两次输入的密码不一致，请重新输入");
        }
        unset($data["confirm_password"]);
        $data["department_id"] = $request->get("department_id", "0");
        DB::beginTransaction();
        try {
            $memberInfo = Member::addForData($data);
            MemberAccess::addForData(["uid" => $memberInfo["uid"], "role_id" => 3]);
            $userInfo = Member::login($memberInfo);
            Member::updateForData($userInfo["uid"], ["openid" => $request->get("openid", "")]);
            config(["webconfig.userInfo" => $userInfo]);
            list($token, $exp) = Kernel::token()->create($userInfo);
            Log::create($request);
            DB::commit();
            return Response::success(["data" => ["token" => $token, "exp" => $exp]]);
        } catch (Exception $exception) {
            DB::rollBack();
            return Response::fail($exception->getMessage());
        }
    }
}
