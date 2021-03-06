<?php
/**
 *  FileName: Custom.php
 *  Description :
 *  Author: DC
 *  Date: 2019/5/21
 *  Time: 16:33
 */


namespace App\Models;

use App\Api\Utils\Constant;
use App\Api\Utils\Response;
use App\Api\Requests\ListRequest;
use App\Models\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Kernel\Kernel;

class Custom extends Model
{
    protected $table = "custom";

    public static function addAttributes($model)
    {
        $userInfo = config("webconfig.userInfo");
        $model->uid = $userInfo["uid"];
        $model->department_id = $userInfo["department_id"];
        $model->in_high_seas = 0;
        list($model->longitude, $model->latitude) = Kernel::maps()->getGps($model->address, $model->city);
        $time = time();
        $model->cid = (int)$model->cid;
        $model->discount = (int)$model->discount;
        $model->follow_up_time = $time;
        $model->create_time = $time;
        $model->delete_time = 0;
        return $model;
    }

    public static function getParams(ListRequest $request, $size = 15)
    {
        list(, $params, $arr, $page, $size) = parent::getParams($request, $size);
        $condition = array("b.delete_time = 0");

        //根据列表类型返回对应的数据
        //其中包含了客户列表、公海池、回收站
        //在客户列表中也根据访问用户的数据权限分为了，自己、部门和所有

        $type = $request->get('type', "");
        $userInfo = config("webconfig.userInfo");
        switch ($type) {
            case "list":
                switch ($userInfo["data_authority"]) {
                    case "self":
                        $condition[] = "a.uid = ? and a.in_high_seas = 0 and a.delete_time = 0";
                        $params[] = $userInfo['uid'];
                        break;
                    case "department":
                        $condition[] = "a.department_id = ? and a.in_high_seas = 0 and a.delete_time = 0";
                        $params[] = $userInfo['department_id'];
                        break;
                    case "all":
                        $condition[] = "a.in_high_seas = 0 and a.delete_time = 0";
                        break;
                    default:
                        throw new HttpResponseException(Response::fail(Constant::SYSTEM_DATA_EXCEPTION_CODE . " - " . Constant::SYSTEM_DATA_EXCEPTION_MESSAGE));
                        break;
                }
                break;
            case "high_seas":
                $condition[] = "a.department_id = ? and a.in_high_seas = 1 and a.delete_time = 0";
                $params[] = $userInfo['department_id'];
                break;
            case "trash":
                $condition[] = "a.in_high_seas = 1 and a.delete_time > 0";
                break;
            default:
                throw new HttpResponseException(Response::fail(Constant::SYSTEM_DATA_EXCEPTION_CODE . " - " . Constant::SYSTEM_DATA_EXCEPTION_MESSAGE));
                break;
        }

        //获取公司名以及编号中包含对应关键字的客户档案
        $keyword = $request->get('keyword', "");
        if ($keyword != "") {
            $condition[] = "(a.name like ? or a.identify like ?)";
            $params[] = trim($keyword) . "%";
            $params[] = trim($keyword) . "%";
        }

        //根据部门ID过滤数据
        $department_id = $request->get('department_id', "all");
        if(($type == "list" && $userInfo["data_authority"] != "department" && $department_id != "all") || ($type == "trash")){
            $condition[] = "a.department_id = ?";
            $params[] = $department_id;
        }

        //获取公司名以及编号中包含对应关键字的客户档案
        $contacts_name = $request->get('contacts_name', "");
        if ($contacts_name != "") {
            $condition[] = "(b.name like ?)";
            $params[] = "%" . trim($contacts_name) . "%";
        }

        //获取对应省份的客户档案
        $province = $request->get('province', "");
        if ($province != "") {
            $condition[] = "a.province = ?";
            $params[] = trim($province);
        }

        //获取对应城市的客户档案
        $city = $request->get('city', "");
        if ($city != "") {
            $condition[] = "a.city = ?";
            $params[] = trim($city);
        }

        //获取对应区/县的客户档案
        $area = $request->get('area', "");
        if ($area != "") {
            $condition[] = "a.area = ?";
            $params[] = trim($area);
        }

        //获取什么时间段后建立的客户档案
        $start_time = $request->get('start_time', "");
        if ($start_time != "") {
            $params[] = strtotime($start_time);
            $condition[] = "a.create_time >= ?";
        }

        //获取什么时间段前建立的客户档案
        $end_time = $request->get('end_time', "");
        if ($end_time != "") {
            $params[] = strtotime($end_time);
            $condition[] = "a.create_time <= ?";
        }

        //获取几天前根据的记录
        $no_follow_up_days = $request->get('no_follow_up_days', "");
        if ($no_follow_up_days != "") {
            $params[] = strtotime(date("Y-m-d"), time()) - ((int)$no_follow_up_days * 3600 * 24);
            $condition[] = "a.follow_up_time <= ?";
        }

        $condition = implode(" and ", $condition);
        return array($condition, $params, $arr, $page, $size);
    }

    public static function getCountParams(ListRequest $request, $size = 15)
    {
        list(, $params, $arr, $page, $size) = parent::getParams($request, $size);
        $condition = array();

        //根据列表类型返回对应的数据
        //在客户列表中也根据访问用户的数据权限分为了，自己、部门和所有
        $userInfo = config("webconfig.userInfo");
        switch ($userInfo["data_authority"]) {
            case "self":
                $condition[] = "a.uid = ? and a.department_id = ? and a.in_high_seas = 0 and a.delete_time = 0";
                $params[] = $userInfo['uid'];
                $params[] = $userInfo['department_id'];
                break;
            case "department":
                $condition[] = "a.department_id = ? and a.in_high_seas = 0 and a.delete_time = 0";
                $params[] = $userInfo['department_id'];
                break;
            case "all":
                $condition[] = " a.in_high_seas = 0 and a.delete_time = 0";
                break;
            default:
                throw new HttpResponseException(Response::fail(Constant::SYSTEM_DATA_EXCEPTION_CODE ." - ".Constant::SYSTEM_DATA_EXCEPTION_MESSAGE));
                break;
        }

        //获取公司名以及编号中包含对应关键字的客户档案
        $keyword = $request->get('keyword', "");
        if ($keyword != "") {
            $condition[] = "(a.name like ? or a.identify like ?)";
            $params[] = trim($keyword) . "%";
            $params[] = trim($keyword) . "%";
        }

        //获取对应省份的客户档案
        $province = $request->get('province', "");
        if ($province != "") {
            $condition[] = "a.province = ?";
            $params[] = trim($province);
        }

        //获取对应城市的客户档案
        $city = $request->get('city', "");
        if ($city != "") {
            $condition[] = "a.city = ?";
            $params[] = trim($city);
        }

        //获取对应区/县的客户档案
        $area = $request->get('area', "");
        if ($area != "") {
            $condition[] = "a.area = ";
            $params[] = trim($area);
        }

        //获取什么时间段后建立的客户档案
        $start_time = $request->get('start_time', "");
        if ($start_time != "") {
            $params[] = strtotime($start_time);
            $condition[] = "a.create_time >= ?";
        }

        //获取什么时间段前建立的客户档案
        $end_time = $request->get('end_time', "");
        if ($end_time != "") {
            $params[] = strtotime($end_time);
            $condition[] = "a.create_time <= ?";
        }

        $condition = implode(" and ", $condition);
        return array($condition, $params, $arr, $page, $size);
    }
}