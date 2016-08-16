<?php

namespace App\Http\Controllers;

date_default_timezone_set('PRC');

use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Config;
use App\Tools\Tools;
use App\Permission;
use App\Role;
use App\User;
use Validator;
use Auth;
use Entrust;
use Illuminate\Support\Facades\Session;

class AdminController extends Controller {

     private $page = 5;
     public function __construct()
     {
	           parent::__construct();
     }

     public function index(){
       	return view('admin/index',["userPermission"=>$this->permissionUser]);
     }
     public function complaint_detail_list()
     {
         $fcid = trim(Request::input('fcid'));
         $res = array();
         $res['err'] = 0;
         $res['msg'] = '';

         if ($fcid <= 0) {
             $res['err'] = -1;
             $res['msg'] = '参数错误';
             goto END;
         }

         $timeout = Config::get('eat.get_complaint_detail_url_timeout');
         $url = Config::get('eat.complaint_detail_url') . '?id=' . $fcid;
         $ret = Tools::curl_get($url, $timeout);
         if ($ret == -1) {
             $res['err'] = -1;
             $res['msg'] = '请求超时';
             goto END;
         }
         $data = json_decode($ret);
         if ($data->success == false) {
             $res['err'] = -1;
             $res['msg'] = '请求失败';
             $res['data'] = $data->error;
             goto END;
         }

         $res['data'] = $data->data;

END:
         return response()->json($res);
     }
     public function complaintList()
     {
          $type = trim(Request::input('type'));
          switch ($type) {
              case 'detail': // 凭证查看请求
                  return $this->complaint_detail_list();
          }
          // 0等待买家确认 1等待卖家确认2等待财务确认3完成
          $showType = intval(Request::input('showtype'));
          $status = intval(Request::input('status'));
          $res = $this->db->table('tb_flow_complaint as a')
                    ->leftjoin('tb_buyer as b', 'a.buyid', '=', 'b.buyid')
                    ->leftjoin('tb_saler as c', 'a.saleid', '=', 'c.saleid');
          if(!empty($status))
          {
              if($status==4) $status=0;
              $res = $res->where('result',$status);
          }
          $res = $res->select('salename','buyname','a.createtime','a.state','a.reason','result','fcid','a.fromtime','a.endtime','a.operuser','note')
                    ->paginate($this->page);
          return view('admin/complaintlist',["res"=>$res,"showType"=>$showType ,"status"=>$status,"userPermission"=>$this->permissionUser]);
     }
     public function complaintOpt()
     {
          $fcid = intval(Request::input('fcid'));
          $note = trim(Request::input('note'));
          $opt = trim(Request::input('opt'));
          //$approver = trim(Request::input('approver'));
          $ret = array();
          $ret['err'] = 0;
          $ret['msg'] = '';

          if ($fcid <= 0) {
              $ret['err'] = -1;
              $ret['msg'] = '参数错误';
              goto END;
          }

          $userinfo = Session::get('userinfo');

          switch ($opt) {
              case 'allow': // 通过
                  $flowComplaintInfo = $this->db->table('tb_flow_complaint')->where('fcid',$fcid)->first();
                  if ($flowComplaintInfo == null) {
                      $ret['err'] = -1;
                      $ret['msg'] = 'FCID错误';
                      goto END;
                  }
                  $state = $flowComplaintInfo->state;
                  $result = $flowComplaintInfo->result;
                  if ($state == 2) {
                      $ret['err'] = -1;
                      $ret['msg'] = '已经被驳回，无法修改';
                      goto END;
                  }
                  $result = $result + 1;
                  if ($result == 3) {
                      $this->db->table('tb_flow_complaint')->where('fcid',$fcid)->update(['result'=>$result,'state'=>1,'operuser'=>Tools::utf8_to_gb2312($userinfo['email'])]);

                      // 发送给分配系统
                      $notifyData = ['city'=>Tools::gb2312_to_utf8($flowComplaintInfo->city),'flowdept'=>Tools::gb2312_to_utf8($flowComplaintInfo->flowdept),'buyerid'=>$flowComplaintInfo->buyid,'saleid'=>$flowComplaintInfo->saleid];
                      $url = Config::get('eat.complaint_url');
                      //发送通知
                      $jsonData = json_encode($notifyData);
                      $result = Tools::curl_post($url,$jsonData,30);
                      $jsonResult = json_decode($result,true);
                      //if(!isset($jsonResult['success'])){
                          error_log('notify '.$url.' error:'.var_export($notifyData,true)."\n return result:".$result);
                      //}
                  } else if ($result == 1 || $result == 2) {
                      $this->db->table('tb_flow_complaint')->where('fcid',$fcid)->update(['result'=>$result,'operuser'=>Tools::utf8_to_gb2312($userinfo['email'])]);
                  } else {

                  }
                  break;
              case 'reject': // 驳回
                  $this->db->table('tb_flow_complaint')->where('fcid',$fcid)->update(['operuser'=>Tools::utf8_to_gb2312($userinfo['email']),'note'=>Tools::utf8_to_gb2312($note),'state'=>2]);
                  break;
              default:
                  $ret['err'] = -1;
                  $ret['msg'] = '没有这个操作';
                  break;
          }

END:
          return response()->json($ret);
     }
     public function salerList($value='')
     {
        $search_type = Request::input('search-type');
        $search = trim(Request::input('search'));

        // 买家列表
        $res = $this->db->table('tb_saler');
        if ($search != '') {
            $search_tmp = strtoupper(PHP_OS)=='WINNT'?$search:mb_convert_encoding($search, 'GB2312', 'utf-8');
            switch ($search_type) {
                case 'type-name':
                    $res = $res->where('salename', 'like', '%'.$search_tmp.'%');
                    break;
                case 'typ-phone':
                    $res = $res->where('phone', 'like', '%'.$search_tmp.'%');
                    break;
                case 'type-code':
                    $res = $res->where('logincode', 'like', '%'.$search_tmp.'%');
                    break;
                default:
                    break;
            }
        }
        $res = $res->leftjoin('eat_admin.dbo.tb_user_merchant', function($leftjoin) {
          $leftjoin->on('tb_user_merchant.merchantid', '=', 'tb_saler.saleid')
                ->where('tb_user_merchant.type', '=', 1);
        })
        ->leftjoin('eat_admin.dbo.users', 'users.id', '=', 'tb_user_merchant.userid');

        if (!$this->permissionUser->hasRole('admin') && !$this->permissionUser->can('/admin/assignmerchantopt')) {
            $res = $res->where('users.id', Session::get('userinfo')['id']);
        }
        $res = $res->leftjoin('tb_flow_day_summary_log', function($leftjoin) {
            $leftjoin->on('tb_saler.saleid', '=', 'tb_flow_day_summary_log.userid')
                    ->where('tb_flow_day_summary_log.flag', '=', 1)
                    ->where('tb_flow_day_summary_log.stattime', '=', date('Y-m-d'));
        })
        ->select('tb_saler.*','spent','chatcnt','email','users.id');
        $res = $res->paginate($this->page)
        ->appends(['search-type'=>$search_type,'search'=>$search]);


        	//每个卖家所有的产品数
        // $productCntList= $this->db->table('tb_flow_info as a')
       	// ->select('saleid',DB::raw('count(*)as total_cnt'))
       	// ->groupBy('saleid')
       	// ->get();
       	// $productCntMap =[];
       	// foreach ($productCntList as $value) {
       	// 	$productCntMap[$value->saleid] =$value-> total_cnt;
       	// }

      	// 搜索全部客服的人员,供分配客服用
        $likerolename = Config::get('eat.search_kefu_keyword');
      	$likerolename = strtoupper(PHP_OS)=='WINNT'?$likerolename:mb_convert_encoding($likerolename, 'GB2312', 'utf-8');
       	$csalist = DB::table('users')
                        ->leftjoin('role_user', 'role_user.user_id', '=', 'users.id')
                        ->leftjoin('roles', 'role_user.role_id', '=', 'roles.id')
                        ->where('roles.name', 'like', "%$likerolename%")
                        ->get(['users.id', 'users.name', 'users.email']);

       	return view('admin/salerlist',["res"=>$res,"userPermission"=>$this->permissionUser,"csalist"=>$csalist,"search_type"=>$search_type, "search"=>$search]);
     }
     public function chargeList()
     {
      $state = Request::input('state');
      $status = Request::input('status');
      $start_date = Request::input('start_date');
      $end_date = Request::input('end_date');
      $search_type = Request::input('search_type');      
      $search = trim(Request::input('search'));

     	$res= $this->db->table('tb_buyer_charge as a')
       	->leftjoin('tb_buyer as b', 'a.buyid', '=', 'b.buyid');
            if($state==0)
            {
              $res= $res->where('state',$state);
            }else
            {
              $res= $res->where('state','>','0');
            }
            if(!empty($status))
            {
              $tempStatus = $status==4?0:$status;
              $res= $res->where('state',$tempStatus);
            }
            if(!empty($start_date))
            {              
              $res= $res->where('a.createtime','>=',$start_date);
            }
            if(!empty($end_date))
            {              
              $res= $res->where('a.createtime','<=',$end_date);
            }
            if ($search != '') {
              $search_tmp = strtoupper(PHP_OS)=='WINNT'?$search:mb_convert_encoding($search, 'GB2312', 'utf-8');              
            switch ($search_type) {
                case 'type-name':
                    $res = $res->where('buyname', 'like', '%'.$search_tmp.'%');
                    break;
                case 'type-bank':
                    $res = $res->where('bank', 'like', '%'.$search_tmp.'%');
                    break;
                case 'type-code':
                    $res = $res->where('logincode', 'like', '%'.$search_tmp.'%');
                    break;
                default:
                    break;
            }
        }
       	$res = $res->select('buyname','amount','dept','a.createtime as createtime','state','pcid','note','filename','bank')
       	->paginate($this->page)
        ->appends(['state'=>$state,'status'=>$status,'start_date'=>$start_date,'end_date'=>$end_date,'search_type'=>$search_type,"search"=>$search]);

       	return view('admin/chargelist',["res"=>$res,"state"=>$state,'start_date'=>$start_date,'end_date'=>$end_date,'status'=>$status,'search_type'=>$search_type,"search"=>$search,"userPermission"=>$this->permissionUser, "charge_money_order_url"=>Config::get('eat.charge_money_order_url')]);
     }
     public function chargeOpt()
     {
     	$type = Request::input('type');
     	switch ($type) {
     		case 'upload':
     			$this->chargeUpload();
     			break;
          case 'auditfirst':
                  $this->auditFirst();
                  break;
          case 'auditsecond':
                  $this->auditSecond();
                  break;
     		default:
     			# code...
     			break;
     	}
     }
     private function auditFirst()
    {
          // state 0 未审核 1已入账 2不同意 3撤销

         $auditpcid = Request::input('auditpcid');
         $auditresult = Request::input('auditresult');  //0未审核 1已入账 2不同意 3撤销
         $note = Request::input('note');
         $note = strtoupper(PHP_OS)=='WINNT'?$note:mb_convert_encoding($note, 'GB2312', 'utf-8');
         $buyerChargeObj = $this->db->table('tb_buyer_charge')->where('pcid',$auditpcid)->get();
         if(isset($buyerChargeObj[0])) {
            $this->db->select("update tb_buyer_charge set state=$auditresult,note='{$note}' where pcid=$auditpcid");
            if($auditresult == 1)
            {
              $this->db->select("update tb_buyer set balance=balance+{$buyerChargeObj[0]->amount} where buyid={$buyerChargeObj[0]->buyid}");
              $balance = $this->db->table('tb_buyer')->where('buyid',$buyerChargeObj[0]->buyid)->value('balance');
              $result = $this->db->table('tb_buyer_balance_log')->where('buyid',$buyerChargeObj[0]->buyid)
                            ->insert(['buyid'=>$buyerChargeObj[0]->buyid,'opertype'=>0,'result'=>$buyerChargeObj[0]->amount,'balance'=>$balance]);
              if ($result != 1) {
                  // 日志插入数据库失败。需要记录日志。
                  error_log('insert tb_buyer_balance_log error, '.$buyerChargeObj[0]->buyid.' amount:'.$buyerChargeObj[0]->amount.' balance:'.$balance);
              }
              $this->insert_message($buyerChargeObj[0]->buyid, 0, 3, '充值成功');
            }
         }
         echo "<script>location.href='{$_SERVER['HTTP_REFERER']}'</script>";
    }
    private function auditSecond()
    {
         $auditreturnpcid = Request::input('auditreturnpcid');
         $note = Request::input('note');
         $note = strtoupper(PHP_OS)=='WINNT'?$note:mb_convert_encoding($note, 'GB2312', 'utf-8');
         $buyerChargeObj = $this->db->table('tb_buyer_charge')->where('pcid',$auditreturnpcid)->get();
         $buyerObj = $this->db->table('tb_buyer')->where('buyid',$buyerChargeObj[0]->buyid)->get();
         if($buyerChargeObj[0]->amount > $buyerObj[0]->balance)
         {
            echo "<script>alert('账户余额小于撤销金额，不能撤销');location.href='{$_SERVER['HTTP_REFERER']}'</script>";
            exit;
         }
         $this->db->select("update tb_buyer_charge set state=3,note='{$note}' where pcid=$auditreturnpcid");
         $this->db->select("update tb_buyer set balance=balance-{$buyerChargeObj[0]->amount} where buyid={$buyerChargeObj[0]->buyid}");
         $balance = $this->db->table('tb_buyer')->where('buyid',$buyerChargeObj[0]->buyid)->value('balance');
         $result = $this->db->table('tb_buyer_balance_log')->where('buyid',$buyerChargeObj[0]->buyid)
                       ->insert(['buyid'=>$buyerChargeObj[0]->buyid,'opertype'=>0,'result'=>(0 - $buyerChargeObj[0]->amount),'balance'=>$balance]);
         if ($result != 1) {
             // 日志插入数据库失败。需要记录日志。
             error_log('insert tb_buyer_balance_log error, '.$buyerChargeObj[0]->buyid.' amount:'.(0 - $buyerChargeObj[0]->amount).' balance:'.$balance);
         }
         $this->insert_message($buyerChargeObj[0]->buyid, 0, 3, '充值撤销成功');
         echo "<script>location.href='{$_SERVER['HTTP_REFERER']}'</script>";
    }
     private function chargeUpload()
     {
     	if(Request::hasFile('myfile') && Request::file('myfile') -> isValid())
     	{
     		$pcid = Request::input('pcid');
     		$filePath =Config::get('eat.uploadPath');
     		$fileName = "upload/".time()."_".mt_rand(1000,9999).".".Request::file('myfile')->getClientOriginalExtension();

     		$path = Request::file('myfile')-> move($filePath .$fileName);

     		$this->db->table('tb_buyer_charge')
     		->where('pcid' ,$pcid)->update(['filename'=>$fileName]);
     		echo "<script>alert('上传成功');location.href='/admin/chargelist'</script>";
     	}
     	else{
     		var_dump(Request::hasFile('myfile'));
     	}
     }
     public function productList($value='')
     {
         	$saleid = Request::input('saleid');
      	$dept = Request::input('dept');
      	$citygroup = Request::input('citygroup');

      	$res= $this->db->table('tb_flow_info as a')
        	  ->leftjoin('tb_saler as b', 'a.saleid', '=', 'b.saleid');
        	// ->select('a.saleid as saleid','flowdept','citygroup','price','create_time','ispublish','salename')
        	// ->groupBy('a.saleid','flowdept','citygroup','price','create_time','ispublish','salename') ;

      	if(!empty($citygroup))
      	{
      		$res = $res->leftjoin('tb_city_group as c', 'a.city', '=', 'c.city');
      		$tmpCityGroup = strtoupper(PHP_OS)=='WINNT'?$citygroup:mb_convert_encoding($citygroup, 'GB2312', 'utf-8');
      		$res = $res->where('c.citygroup', '=', $tmpCityGroup );
      	}
      	if(!empty($saleid))
      	{
      		$res = $res->where('a.saleid', '=', $saleid);
      	}
      	if(!empty($dept))
      	{
      		$tmpdept = strtoupper(PHP_OS)=='WINNT'?$dept:mb_convert_encoding($dept, 'GB2312', 'utf-8');
      		$res = $res->where('flowdept', '=', $tmpdept);
      	}
        $res = $res->orderBy('fid','desc');
      	$res = $res->select('a.saleid as saleid','flowdept','a.city','price','a.createtime','ispublish','salename','fid');
      	$res = $res->paginate($this->page)
      	->appends(['saleid'=>$saleid, 'dept'=>$dept, 'citygroup'=>$citygroup]);

      	$salerlist = $this->db->table('tb_saler')->get(['saleid', 'salename']);
      	$citygrouplist = $this->db->table('tb_city_group')->distinct('citygroup')->get(['citygroup']);
     	$flowdeptlist = Config::get('eat.flowdept_list');
     	$searchConditon = ['saleid'=>$saleid,'dept'=>$dept,'citygroup'=> strtoupper(PHP_OS)=='WINNT'?$citygroup:mb_convert_encoding($citygroup, 'GB2312', 'utf-8')];

    	return view('admin/productlist',["res"=>$res,'salerlist'=>$salerlist,'citygrouplist'=>$citygrouplist,'flowdeptlist'=>$flowdeptlist,'search'=>$searchConditon,"userPermission"=>$this->permissionUser]);
      }
      private function productAdd()
      {
          $saleid = intval(Request::input('saleid'));
          $flowdept = Request::input('flowdept');
          $citygroup = Request::input('citygroup');
          $price = round(floatval(Request::input('price')), 2);
          $ispublish = intval(Request::input('ispublish'));
          $type = Request::input('type');
          $privateflag = intval(Request::input('privateflag'));
          $privatecode = Request::input('privatecode');
          $privatemaxtime = Request::input('privatemaxtime');
          $balance = Request::input('balance');
          $count = 0;
          $ret = array();
          $ret['err'] = 0;
          $ret['msg'] = '';
          $createdate = date('Y-m-d H:i:s');

          if ($saleid <= 0) {
              $ret['err'] = 1;
              $ret['msg'] = '参数错误';
              goto END;
          }

          $flowdept = strtoupper(PHP_OS)=='WINNT'?$flowdept:mb_convert_encoding($flowdept, 'GB2312', 'utf-8');
          $citygroup = strtoupper(PHP_OS)=='WINNT'?$citygroup:mb_convert_encoding($citygroup, 'GB2312', 'utf-8');
          if ($privateflag == 0) {
              $count = $this->db->table('tb_flow_info')
                  ->where('saleid', $saleid)
                  ->where('flowdept', $flowdept)
                  ->leftjoin('tb_city_group', 'tb_city_group.city', '=', 'tb_flow_info.city')
                  ->where('tb_city_group.citygroup', $citygroup)
                  ->count();
          } else {
              if (empty($privatecode) || empty($privatemaxtime) || empty($balance)) {
                  $ret['err'] = -1;
                  $ret['msg'] = '参数错误';
                  goto END;
              }
              $count = $this->db->table('tb_flow_private_info')
                  ->where('sale_id', $saleid)
                  ->where('flowdept', $flowdept)
                  ->leftjoin('tb_city_group', 'tb_city_group.city', '=', 'tb_flow_info.city')
                  ->where('tb_city_group.citygroup', $citygroup)
                  ->count();
          }
          if ($count > 0) {
              $ret['err'] = 2;
              $ret['msg'] = '已经有这个类型的产品';
              goto END;
          }
          $params = array();
          $citylist = $this->db->table('tb_city_group')->where('citygroup', $citygroup)->lists('city');
          foreach ($citylist as $key => $value) {
              $params[$key] = array();
              if ($privateflag == 0) {
                  $params[$key]['saleid'] = $saleid;
              } else {
                  $params[$key]['sale_id'] = $saleid;
              }
              $params[$key]['flowdept'] = $flowdept;
              $params[$key]['city'] = $value;
              $params[$key]['price'] = $price;
              $params[$key]['source'] = '';
              $params[$key]['createtime'] = $createdate;
              $params[$key]['ispublish'] = 0;
              if ($privateflag != 0) {
                  $params[$key]['privatecode'] = $privatecode;
                  $params[$key]['privatemaxtime'] = $privatemaxtime;
                  $params[$key]['balance'] = $balance;
              }
              $notifyData[] = ['city'=>strtoupper(PHP_OS)=='WINNT'?$value:mb_convert_encoding($value, 'utf-8', 'GB2312'),
              'flowdept'=>Request::input('flowdept'),'salerid'=>$saleid,'price'=>$price];
          }

          $url = Config::get('eat.flow_publish_url');
          //发送通知
          $jsonData = json_encode($notifyData);
          $result = Tools::curl_post($url,$jsonData,30);
          $jsonResult = json_decode($result,true);
          //if(!isset($jsonResult['success'])){
              error_log('notify '.$url.' error:'.var_export($notifyData,true)."\n return result:".$result);
          //}
          if ($privateflag == 0) {
              $this->db->table('tb_flow_info')->insert($params);
          } else {
              $this->db->table('tb_flow_private_info')->insert($params);
          }
          $this->insert_message($saleid, 1, 4, '产品发布成功');
END:
          return $ret;
      }
      private function productEdit()
      {
          $fid = Request::input('fid');
          $saleid = intval(Request::input('saleid'));
          $flowdept = Request::input('flowdept');
          $citygroup = Request::input('citygroup');
          $price = round(floatval(Request::input('price')), 2);
          $privateflag = intval(Request::input('privateflag'));
          $ret = array();
          $ret['err'] = 0;
          $ret['msg'] = '';

          $flowdept = strtoupper(PHP_OS)=='WINNT'?$flowdept:mb_convert_encoding($flowdept, 'GB2312', 'utf-8');
          $citygroup = strtoupper(PHP_OS)=='WINNT'?$citygroup:mb_convert_encoding($citygroup, 'GB2312', 'utf-8');
          if ($privateflag == 0) {
              $this->db->table('tb_flow_info')
                  ->where('$fid', $fid)
                  ->update(['price' => $price, 'flowdept' => $flowdept, 'saleid'=>$saleid]);
          } else {
              $this->db->table('tb_flow_private_info')
                  ->where('sale_id', $saleid)
                  ->update(['price' => $price, 'flowdept' => $flowdept, 'sale_id'=>$saleid]);
          }

          return $ret;
      }
      private function productState()
      {
          $fid = explode(',', Request::input('fid'));
          $privateflag = intval(Request::input('privateflag'));
          $ispublish = intval(Request::input('ispublish'));
          $ret = array();
          $ret['err'] = 0;
          $ret['msg'] = '';

          if ($fid == '' || count($fid) <= 0) {
              $ret['msg'] = '0';
              goto END;
          }
          // 暂时只改变了数据库的ispublish位，没有通知分发系统。
          if ($privateflag == 0) {
              $this->db->table('tb_flow_info')
                  ->where('fid', $fid)
                  ->update(['ispublish' => $ispublish]);
          } else {
              $this->db->table('tb_flow_private_info')
                  ->where('fid', $fid)
                  ->update(['ispublish' => $ispublish]);
          }
END:
          return $ret;
      }
      private function productPriceEdit()
      {
          $fidlist = explode(',', Request::input('fid'));
          $privateflag = intval(Request::input('privateflag'));
          $price = round(floatval(Request::input('price')), 2);
          $ret = array();
          $ret['err'] = 0;
          $ret['msg'] = '';

          if ($fidlist == '' || count($fidlist) <= 0) {
              $ret['msg'] = '0';
              goto END;
          }
          if ($privateflag == 0) {
              $datas = $this->db->table('tb_flow_info')->whereIn('fid', $fidlist)->get();
              foreach ($datas as $key => $value) {
                  $notifyData[] = ['city'=>strtoupper(PHP_OS)=='WINNT'?$value->city:mb_convert_encoding($value->city, 'utf-8', 'GB2312'),
                  'flowdept'=>$value->flowdept,'salerid'=>$value->saleid,'price'=>$price];
              }

              $url = Config::get('eat.flow_publish_url');
              //发送通知
              $jsonData = json_encode($notifyData);
              $result = Tools::curl_post($url,$jsonData,30);
              $jsonResult = json_decode($result,true);
              //if(!isset($jsonResult['success'])){
                  error_log('endit notify '.$url.' error:'.var_export($notifyData,true)."\n return result:".$result);
              //}
              if(isset($jsonResult['success'])){
                  $this->db->table('tb_flow_info')
                      ->whereIn('fid', $fidlist)
                      ->update(['price' => $price]);
              }
          } else {
              $this->db->table('tb_flow_private_info')
                  ->whereIn('fid', $fidlist)
                  ->update(['price' => $price]);
          }
END:
          return $ret;
      }
      public function productOpt()
      {
          $saleid = intval(Request::input('saleid'));
          $flowdept = Request::input('flowdept');
          $citygroup = Request::input('citygroup');
          $price = Request::input('price');
          $ispublish = intval(Request::input('ispublish'));
          $type = Request::input('type');
          $privateflag = intval(Request::input('privateflag'));
          $privatecode = Request::input('privatecode');
          $privatemaxtime = Request::input('privatemaxtime');
          $balance = Request::input('balance');

          $ret = array();
          $ret['err'] = 0;
          $ret['msg'] = '';

          if (empty($type)) {
              $ret['err'] = -1;
              $ret['msg'] = '参数错误';
              goto END;
          }

          switch ($type) {
              case 'add': // 添加新产品
                  $ret = $this->productAdd();
                  break;
              case 'edit':
                  $ret = $this->productEdit();
                  break;
              case 'priceedit': // 修改价格
                  $ret = $this->productPriceEdit();
                  break;
              case 'state': // 修改状态（是否上架）
                  $ret = $this->productState();
                  break;
              default:
                  $ret['err'] = -1;
                  $ret['msg'] = '没有此项操作';
                  break;
          }
  END:
          return response()->json($ret);
      }
      public function orderList($value='')
      {
        $search_type = Request::input('search_type');
        $search = trim(Request::input('search'));
        $flowdept = trim(Request::input('flowdept'));
        $city = trim(Request::input('city'));
       	$res= $this->db->table('tb_flowpack_order as a')
       	->leftjoin('tb_buyer as d','d.buyid','=','a.buyid');
        if ($search != '') {
            $search_tmp = strtoupper(PHP_OS)=='WINNT'?$search:mb_convert_encoding($search, 'GB2312', 'utf-8');
            switch ($search_type) {
                case 'type-name':
                    $res = $res->where('buyname', 'like', '%'.$search_tmp.'%');
                    break;
                case 'typ-orderid':
                    $res = $res->where('orderid', 'like', '%'.$search_tmp.'%');
                    break;
                case 'type-code':
                    $res = $res->where('logincode', 'like', '%'.$search_tmp.'%');
                    break;
                default:
                    break;
            }
        }
        if(!empty($flowdept)){
          $flowdept_tmp = strtoupper(PHP_OS)=='WINNT'?$search:mb_convert_encoding($flowdept, 'GB2312', 'utf-8');
          $res = $res->where('flowdept',$flowdept_tmp);
        }
        if(!empty($city)){
          $city_tmp = strtoupper(PHP_OS)=='WINNT'?$search:mb_convert_encoding($city, 'GB2312', 'utf-8');
          $res = $res->where('city',$city_tmp);
        }
        $res = $res->select('a.createtime','orderid','buyname','city','flowdept','packno','pricefrom','priceto','taketime','maxtime','a.balance')
       	->paginate($this->page)
        ->appends(['search_type'=>$search_type,"search"=>$search,"flowdept"=>$flowdept,'city'=>$city]);

        $flowdeptlist = Config::get('eat.flowdept_list');
        $citylist = $this->db->table('tb_city_group')->get(['city']);

       	return view('admin/orderlist',["res"=>$res,'search_type'=>$search_type,"search"=>$search,'city'=>$city,"flowdept"=>$flowdept,'flowdeptlist'=>$flowdeptlist,"citylist"=>$citylist,"userPermission"=>$this->permissionUser]);
       }
       public function userList()
       {

       	$username = Request::input('username');
       	if(empty($username))
       	{
       		$username = "";
       	}
       	$res= DB::table('users as u')
       	->leftJoin('role_user as ru', 'u.id', '=', 'ru.user_id')
       	->leftJoin('roles as r', 'r.id', '=', 'ru.role_id')
       	->where('u.name', 'like', '%'.$username.'%')
       	->orderBy('u.id','desc')
       	->select('u.name as username','u.email as nickname','r.name as rolename','u.id as uid','u.created_at as createtime')
       	->paginate($this->page);
       	$roleList = DB::table('roles')->get();

       	return view('admin/userlist',["res"=>$res,"rolelist"=>$roleList,"userPermission"=>$this->permissionUser]);
       }
       public function userOpt()
       {
       	$type = Request::input('type');
       	if( $type=='add' )
       	{
       	      //用户添加和修改保存接口
       	      $uid = Request::input('uid');
       	      $username = Request::input('username');
       	      if(empty($username)){
       	      	return response()->json( ['msg'=>"用户名必须填写"] );
       	      }
              $tmpUsername = strtoupper(PHP_OS)=='WINNT'?$username:mb_convert_encoding($username, 'GB2312', 'utf-8');
              if(empty($uid))
              {
                $unameObj = User::where('name', '=', $tmpUsername)->first();
                if(!empty($unameObj))
                {
                    return response()->json( ['msg'=>"用户名已经存在"] );
                }
              }

       	      $nickname = Request::input('nickname');
       	      if(empty($nickname)){
       	      	return response()->json( ['msg'=>"昵称必须填写"] );
       	      }
              $pwd = Request::input('pwd');
       	      if(empty($uid))
       	      {
       	      	if(empty($pwd)){
       	      		return response()->json( ['msg'=>"密码必须填写"] );
       	      	}
                if(!preg_match("/^[a-zA-Z0-9]+$/", $pwd))
                    return response()->json(['msg'=>"密码必须数字或字母"]);
       	      }
              if($uid && !empty($pwd))
              {
                  if(!preg_match("/^[a-zA-Z0-9]+$/", $pwd))
                    return response()->json(['msg'=>"密码必须数字或字母"]);
              }
       	      $rolename = Request::input('rolename');
                    $rolename = strtoupper(PHP_OS)=='WINNT'?$rolename:mb_convert_encoding($rolename, 'GB2312', 'utf-8');
       	      if(empty($rolename)){
       	      	return response()->json( ['msg'=>"角色必须选择"] );
       	      }

              $username = strtoupper(PHP_OS)=='WINNT'?$username:mb_convert_encoding($username, 'GB2312', 'utf-8');
              $nickname = strtoupper(PHP_OS)=='WINNT'?$nickname:mb_convert_encoding($nickname, 'GB2312', 'utf-8');
       	      if(empty($uid ))
       	      {
       	      	//用户添加
       	              User::create([
		        'name' => $username,
		        'email' => $nickname,
		        'password' => bcrypt($pwd),
		 ]);

       	      }else
       	      {
       	      	//用户修改
       	      	if(empty($pwd)){
       	      		User::where('id', '=',  $uid )->update([
       	      					          'email'=>$nickname,
       	      					          'name' => $username,
       	      					]);
       	      	}else{
       	      		User::where('id', '=',  $uid )->update([
       	      					          'password'=>bcrypt($pwd),
       	      					          'email'=>$nickname,
       	      					          'name' => $username,
       	      					]);
       	      	}
       	      	//删除角色,因为不知道原来有哪些,直接删除在重新添加用户和角色的关系
		$result = DB::table('role_user')->where('user_id','=',$uid)->delete();
		if ( $result < 0 )
		{
		 	return response()->json( ['msg'=>"'数据库删除错误'"] );
		 }
       	      }
       	      //设置角色
       	      $unameObj = User::where('name', '=', $username)->first();
   	      $role = Role::where('name', '=', $rolename)->first();
        	      $unameObj->attachRole($role);

       	      return response()->json( ['msg'=>"ok"] );
       	}
       	elseif( $type=='del' )
       	{
       	        $uidstr = Request::input('uid');
       	        if(empty($uidstr)){
       	      	return response()->json( ['msg'=>"uid is null"] );
       	        }
       	       $uidArr  = explode(",", $uidstr);
       	       foreach ($uidArr  as  $uid) {
       	       	 $userObj = User::where('id', '=',  $uid )->delete();
       	       }
	        return response()->json( ['msg'=>"ok"] );
       	}
       	elseif( $type=='update' )
       	{
    	        	//用户修改页面显示
    	        	$uid = Request::input('uid');
	       	$userObj= DB::table('users as u')
	       	->leftJoin('role_user as ru', 'u.id', '=', 'ru.user_id')
	       	->leftJoin('roles as r', 'r.id', '=', 'ru.role_id')
	       	->where('u.id',$uid)
	       	->get(['u.name as username','u.email as nickname','r.name as rolename','u.id as uid'])	;
    	        	$userArr = [
    	        		'name'=>strtoupper(PHP_OS)=='WINNT'?$userObj[0]->username:mb_convert_encoding($userObj[0]->username, 'utf-8', 'GB2312'),
    	        		'nickname'=>strtoupper(PHP_OS)=='WINNT'?$userObj[0]->nickname:mb_convert_encoding($userObj[0]->nickname, 'utf-8', 'GB2312'),
    	        		'rolename'=>strtoupper(PHP_OS)=='WINNT'?$userObj[0]->rolename:mb_convert_encoding($userObj[0]->rolename, 'utf-8', 'GB2312'),
    	        	];

    	        	return  response()->json(['msg'=>"ok",'data'=>['user'=>$userArr ]]);
       	}
       }

    public function login() {
    	$method = Request::method();
    	$beforeurl = Request::input('before');

    	if (!empty(Session::get('userinfo'))) {
    		return redirect('/admin');
    	}

    	if ( $method=='GET' ) {
    		return view('admin/login', ['errmsg' => '']);
    	} else if ( $method=='POST' ) {
    		$ret = array();
    		$ret['err'] = 0;
    		$ret['errmsg'] = '';

    		$account = Request::input('account');
            $account = strtoupper(PHP_OS)=='WINNT'?$account:mb_convert_encoding($account, 'GB2312', 'utf-8');
    		$passwd = Request::input('passwd');

            if(Auth::attempt(array('name' => $account, 'password' => $passwd))) {
                $userinfo = User::where('name', '=', $account)->first();
    			Session::put("userinfo", ['id' => $userinfo['id'], 'name' =>Request::input('account'), 'email' => strtoupper(PHP_OS)=='WINNT'?$userinfo['email']:mb_convert_encoding($userinfo['email'], 'utf-8', 'GB2312')]);
    			if ($beforeurl == '') {
    				return redirect('/admin');
    			} else {
    				return redirect($beforeurl);
    			}
    		} else {
    			$ret['err'] = -1;
    			$ret['errmsg'] = '用户名或密码不一致';
    		}
    		return view('/admin/login', ['errmsg' => $ret['errmsg']]);
    	}
    }

    public function logout() {
    	Session::forget('userinfo');
    	return redirect('/admin/login');
    }
}
