<?php
/**
 *  FileName: CustomFollowUpController.php
 *  Description :
 *  Author: DC
 *  Date: 2019/6/3
 *  Time: 11:55
 */


namespace App\Api\Controllers\V1;

use App\Api\Controllers\Controller;
use App\Api\Requests\CustomFollowUpRequest;
use App\Api\Utils\Pager;
use App\Api\Utils\Response;
use App\Api\Requests\IdsRequest;
use App\Api\Requests\ListRequest;
use App\Models\CustomFollowUpRecord;
use App\Models\CustomFollowUpFile;
use App\Models\Category;
use App\Models\Custom;
use App\Models\Uploads;
use Illuminate\Support\Facades\DB;
use Exception;

class CustomFollowUpController extends Controller
{
    /**
     * 3.1 - 获取跟进记录列表
     * @param ListRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(ListRequest $request)
    {
        $this->validate($request, ["custom_id" => "required|integer"], [], ["custom_id" => "客户ID"]);
        list($condition, $params, $arr, $page, $size) = CustomFollowUpRecord::getParams($request);
        $time = time();
        $orderRaw = "create_time desc";
        $model = DB::table(DB::raw(CustomFollowUpRecord::getTableName()))->selectRaw("*");
        if ($condition != "") {
            $model->whereRaw($condition, $params);
        }
        list($arr['pageList'], $arr['totalPage']) = Pager::create($model->count(), $size);
        $list = $model->forPage($page, $size)->orderByRaw($orderRaw)->get();
        foreach ($list as $key => $value) {
            $value = (array)$value;
            $value["key"] = $time . "_" . $value["id"];
            $value["create_time"] = $this->toDateAgo($value["create_time"], $time);
            $value["files"] = DB::table(DB::raw(CustomFollowUpFile::getTableName()))->selectRaw("type,name,filename,convername")->whereRaw("record_id = :record_id and delete_time = 0", [":record_id" => $value["id"]])->get();
            $list[$key] = $value;
        }
        $arr['list'] = $list;
        return Response::success(["data" => $arr]);
    }

    /**
     * 3.2 - 获取资料文件列表
     * @param ListRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function files(ListRequest $request)
    {
        $this->validate($request, ["custom_id" => "required|integer"], [], ["custom_id" => "客户ID"]);
        list($condition, $params, $arr) = CustomFollowUpFile::getParams($request);
        $orderRaw = "create_time desc";
        $model = DB::table(DB::raw(CustomFollowUpFile::getTableName()))->selectRaw("cid,type,name,filename,convername,create_time");
        if ($condition != "") {
            $model->whereRaw($condition, $params);
        }
        $fileList = $model->orderByRaw($orderRaw)->get();

        $cids = implode(",", $this->toOneDimension($fileList, "cid"));
        $list = DB::table(DB::raw(Category::getTableName()))->selectRaw("id,title")->whereRaw("id in ($cids)")->get();
        foreach ($list as $key => $value) {
            $value = (array)$value;
            foreach ($fileList as $k => $v) {
                $v = (array)$v;
                if ($v["cid"] == $value["id"]) {
                    $v["create_time"] = $this->toDate($v["create_time"]);
                    $value["files"][] = $v;
                }
            }
            $value["file_totle"] = count($value["files"]);
            $list[$key] = $value;
        }
        $arr['list'] = $list;
        return Response::success(["data" => $arr]);
    }

    /**
     * 3.3 - 新增跟进记录
     * @param CustomFollowUpRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function add(CustomFollowUpRequest $request)
    {
        $ids = $request->get("ids", "");
        $data = array_diff_key($request->all(), ["ids" => ""]);

        DB::beginTransaction();
        try {
            $model = CustomFollowUpRecord::addForData($data);
            if ($ids != "") {
                $ids = implode(",", $ids);
                $uploadFileList = Uploads::whereRaw("id in ($ids)")->get();
                foreach ($uploadFileList as $key => $value) {
                    CustomFollowUpFile::addForData([
                        "custom_id" => $model->custom_id,
                        "record_id" => $model->id,
                        "upload_id" => $value->id,
                        "cid" => $model->cid,
                        "type" => $value->type,
                        "name" => $value->name,
                        "filename" => $value->filename,
                        "convername" => $value->convername,
                        "size" => $value->size
                    ]);
                }
                Uploads::updateForIds($ids, ["status" => 1]);
            }
            Custom::updateForData($model->custom_id, ["follow_up_time" => time()]);
            DB::commit();
            return Response::success();
        } catch (Exception $exception) {
            DB::rollBack();
            return Response::fail($exception->getMessage());
        }
    }

    /**
     * 3.4 - 更新跟进记录
     * @param CustomFollowUpRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(CustomFollowUpRequest $request)
    {
        $this->validate($request, ['id' => 'required|integer'], [], ["id" => "记录ID"]);
        $ids = $request->get("ids", "");
        $data = array_diff_key($request->all(), ["ids" => ""]);

        DB::beginTransaction();
        try {
            $model = CustomFollowUpRecord::updateForData($request->get("id"), $data);
            if ($ids != "") {

                //重置已经添加的文件数据
                $oldFileList = CustomFollowUpFile::whereRaw("record_id = :record_id and delete_time = 0", [":record_id" => $model->id])->get()->toArray();
                $oldUploadIds = implode(",", $this->toOneDimension($oldFileList, "upload_id"));
                Uploads::updateForIds($oldUploadIds, ["status" => 0]);
                CustomFollowUpFile::whereRaw("record_id = :record_id and delete_time = 0", [":record_id" => $model->id])->delete();

                //重新添加文件数据
                $ids = implode(",", $ids);
                $uploadFileList = Uploads::whereRaw("id in ($ids)")->get();
                foreach ($uploadFileList as $key => $value) {
                    CustomFollowUpFile::addForData([
                        "custom_id" => $model->custom_id,
                        "record_id" => $model->id,
                        "upload_id" => $value->id,
                        "cid" => $model->cid,
                        "type" => $value->type,
                        "name" => $value->name,
                        "filename" => $value->filename,
                        "convername" => $value->convername,
                        "size" => $value->size
                    ]);
                }
                Uploads::updateForIds($ids, ["status" => 1]);
            }
            Custom::updateForData($model->custom_id, ["follow_up_time" => time()]);
            DB::commit();
            return Response::success();
        } catch (Exception $exception) {
            DB::rollBack();
            return Response::fail($exception->getMessage());
        }
    }

    /**
     * 3.5 - 删除客户跟进记录
     * @param IdsRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(IdsRequest $request)
    {
        DB::beginTransaction();
        try {
            CustomFollowUpRecord::deleteForIds($request->get("ids"));
            CustomFollowUpFile::deleteForIds($request->get("ids"), "record_id");
            DB::commit();
            return Response::success();
        } catch (Exception $exception) {
            DB::rollBack();
            return Response::fail($exception->getMessage());
        }
    }
}