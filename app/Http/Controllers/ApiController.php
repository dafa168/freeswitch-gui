<?php

namespace App\Http\Controllers;

use App\Models\Audio;
use App\Models\Gateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use App\Models\Sip;
use Illuminate\Support\Facades\Redis;

class ApiController extends Controller
{

    //文件上传
    public function upload(Request $request)
    {
        //上传文件最大大小,单位M
        $maxSize = 10;
        //支持的上传图片类型
        $allowed_extensions = ["png", "jpg", "gif"];
        //返回信息json
        $data = ['code'=>1, 'msg'=>'上传失败', 'data'=>''];
        $file = $request->file('file');

        //检查文件是否上传完成
        if ($file->isValid()){
            //检测图片类型
            $ext = $file->getClientOriginalExtension();
            if (!in_array(strtolower($ext),$allowed_extensions)){
                $data['msg'] = "请上传".implode(",",$allowed_extensions)."格式的图片";
                return response()->json($data);
            }
            //检测图片大小
            if ($file->getSize() > $maxSize*1024*1024){
                $data['msg'] = "图片大小限制".$maxSize."M";
                return response()->json($data);
            }
        }else{
            $data['msg'] = $file->getErrorMessage();
            return response()->json($data);
        }
        $newFile = date('Y-m-d')."_".time()."_".uniqid().".".$file->getClientOriginalExtension();
        $disk = Storage::disk('uploads');
        $res = $disk->put($newFile,file_get_contents($file->getRealPath()));
        if($res){
            $data = [
                'code'  => 0,
                'msg'   => '上传成功',
                'data'  => $newFile,
                'url'   => '/uploads/'.$newFile,
            ];
        }else{
            $data['data'] = $file->getErrorMessage();
        }
        return response()->json($data);
    }


    /**
     * 拨打接口
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function dial(Request $request)
    {
        $data = $request->all(['exten','phone','user_data']);
        if ($data['exten'] == null || $data['phone'] == null) {
            return Response::json(['code'=>1,'msg'=>'号码不能为空']);
        }

        //检测10秒重复请求
        if(Redis::get('check_'.$data['exten'])!=null){
            return Response::json(['code'=>1,'msg'=>'重复请求，请稍后再试']);
        }else{
            Redis::setex('check_'.$data['exten'],10,'exist');
        }

        //验证分机信息
        $sip = Sip::where('username',$data['exten'])->first();
        if ($sip == null) {
            return Response::json(['code'=>1,'msg'=>' 外呼号不存在']);
        }

        //验证分机是否登录
        $status = 0;
        $fs = new \Freeswitchesl();
        $service = config('freeswitch.esl');
        try{
            if ($fs->connect($service['host'],$service['port'],$service['password'])) {
                $result = $fs->api("sofia_contact",$data['exten']);
                $result = trim($result);
                //只有已注册的连接不用关闭
                if ($result == 'error/user_not_registered') {
                    $fs->disconnect();
                }else{
                    $status = 1;
                }
            }
            
        }catch (\Exception $exception){
            Log::info('查询分机状态异常：'.$exception->getMessage());
            return Response::json(['code'=>1,'msg'=>'ESL无法连接']);
        }
        
        if ($status == 0){
            return Response::json(['code'=>1,'msg'=>'当前外呼号未登录']);
        }

        //验证手机号码
        if (!preg_match('/\d{4,12}/', $data['phone'])) {
            return Response::json(['code'=>1,'msg'=>'客户电话号码格式不正确']);
        }

        //呼叫字符串
        $aleg_uuid = md5(\Snowflake::nextId(1).$data['exten'].$data['phone'].Redis::incr('fs_id'));
        $bleg_uuid = md5(\Snowflake::nextId(2).$data['phone'].$data['exten'].Redis::incr('fs_id'));
        $dialStr  = "originate {origination_uuid=".$aleg_uuid."}";
        $dialStr .= "{origination_caller_id_number=".$sip->username."}";
        $dialStr .= "{origination_caller_id_name=".$sip->username."}";
        //设置变量
        if ($data['user_data']){
            $dialStr .= "{user_data=".encrypt($data['user_data'])."}";
        }

        //验证内部呼叫还是外部呼叫
        $res = Sip::where('username',$data['phone'])->first();

        if ($res == null) { //外部呼叫
            //查询分机的网关信息
            $gateway = Gateway::with('outbound')->where('id',$sip->gateway_id)->first();
            if ($gateway == null) {
                return Response::json(['code'=>1,'msg'=>'外呼号无可用的网关']);
            }
            //获取网关出局号码
            $outbound = null;
            if ($gateway->outbound_caller_id) {
                $outbound = $gateway->outbound_caller_id;
            }else{
                $gw_key = 'gw'.$gateway->id.'_outbound';
                if (Redis::lLen($gw_key) == 0) {
                    foreach ($gateway->outbound as $d) {
                        Redis::rPush($gw_key,$d->number);
                    }
                }
                $outbound = Redis::lPop($gw_key);
            }
            if ($outbound) {
                $dialStr .= "{effective_caller_id_number=".$outbound."}";
                $dialStr .= "{effective_caller_id_name=".$outbound."}";
            }
            $dialStr .= "{customer_caller=".$data['phone']."}user/".$sip->username." gw".$gateway->id."_";
            //网关后缀SS
            if ($gateway->prefix){
                $dialStr .=$gateway->prefix;
            }
            $dialStr .= $data['phone']."_".$bleg_uuid;
        }else{ //内部呼叫
            $dialStr .="user/".$sip->username." ".$data["phone"];
        }
        $dialStr .=" XML default";
        
        try{
            $fs->bgapi($dialStr);
            $fs->disconnect();
            //20分钟过期
            Redis::setex($data['exten'],1200, $aleg_uuid);
            return Response::json(['code'=>0,'msg'=>'呼叫成功','data'=>['uuid'=>$aleg_uuid,'time'=>date('Y-m-d H:i:s')]]);
        }catch (\Exception $exception){
            Log::info("呼叫错误：".$exception->getMessage());
            return Response::json(['code'=>1,'msg'=>'呼叫失败']);
        }

    }

    /**
     * 挂断
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function hangup(Request $request)
    {
        $exten = $request->get('exten');
        $uuid = !empty($exten) ? Redis::get($exten) : '';
        if(empty($uuid)){
            return Response::json(['code'=>0,'msg'=>'无通话']);
        }
        $sip = Sip::where('username',$exten)->first();
        if ($sip == null) {
            return Response::json(['code'=>1,'msg'=>' 外呼号不存在']);
        }
        
        $fs = new \Freeswitchesl();
        $service = config('freeswitch.esl');
        try{
            if ($fs->connect($service['host'],$service['port'],$service['password'])) {
                $fs->bgapi("uuid_kill",$uuid);
                $fs->disconnect();
                Redis::del($exten);
                return Response::json(['code'=>0,'msg'=>'已挂断']);
            }
            
        }catch (\Exception $exception){
            Log::info('ESL连接异常：'.$exception->getMessage());
            return Response::json(['code'=>1,'msg'=>'连接异常']);
        }
        return Response::json(['code'=>0,'msg'=>'已挂断']);
    }

    /**
     * 语音消息接口
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function voice(Request $request)
    {
        $data = $request->all(['phone','text','gateway_id']);
        //验证参数
        if (!preg_match('/^1[34578][0-9]{9}$/',$data['phone'])){
            return Response::json(['code'=>1,'msg'=>'号码格式不正确']);
        }
        //验证网关信息
        $gw = Gateway::find($data['gateway_id']);
        if ($gw == null){
            return Response::json(['code'=>1,'msg'=>'网关不存在']);
        }
        //合成语音
        $res = (new Audio())->tts($data['text']);
        if ($res['code']!=0){
            return Response::json(['code'=>1,'msg'=>'语音合成失败']);
        }
        //呼叫
        try{
            $fs = new \Freeswitchesl();
            $service = config('freeswitch.esl');
            $fs->connect($service['host'],$service['port'],$service['password']);
            $dialStr = "originate {ignore_early_media=true}";
            if ($gw->outbound_caller_id){
                $dialStr .= "{effective_caller_id_number=".$gw->outbound_caller_id."}";
                $dialStr .= "{effective_caller_id_name=".$gw->outbound_caller_id."}";
            }
            $dialStr .= "sofia/gateway/gw".$gw->id."/";
            if ($gw->prefix){
                $dialStr .= $gw->prefix.$data['phone'];
            }
            $dialStr .= " &playback(".$res['path'].")";
                $fs->bgapi($dialStr);
            $fs->disconnect();
            return Response::json(['code'=>0,'msg'=>'呼叫成功']);
        }catch (\Exception $exception){
            Log::info('ESL连接异常：'.$exception->getMessage());
            return Response::json(['code'=>1,'msg'=>'呼叫异常']);
        }
    }

}
