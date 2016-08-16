<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesResources;
use Illuminate\Support\Facades\Session;
use App\Permission;
use App\Role;
use App\User;
use Validator;
use Auth;
use Entrust;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Request;

class Controller extends BaseController
{
    use AuthorizesRequests, AuthorizesResources, DispatchesJobs, ValidatesRequests;
    public $permissionUser;
    public $db = NULL;
    public function __construct()
    {
	        $conn = app('db');
        	$this->db = $conn->connection('flowtransaction');
     	    //判断用户是否有权限访问
            $userinfo = Session::get('userinfo');
            $uriArr = explode("?", $_SERVER['REQUEST_URI']);


            if (empty(Session::get('userinfo')) || $uriArr[0]=='/admin/logout' || $uriArr[0]=='/admin/login')
            {
    	       	return ;
          	}

          	$username = strtoupper(PHP_OS)=='WINNT'?$userinfo['name']:mb_convert_encoding($userinfo['name'], 'GB2312', 'utf-8');
            $user= User::where('name', '=', $username)->first();
            $this->permissionUser = $user;

          	if($uriArr[0]=='/admin')
            {
            	return;
            }

            if( !$user->hasRole('admin') )
            {
                $can = $user->can($uriArr [0]);
                if( !$can )
                {
                    if(Request::ajax())
                    {
                    	echo json_encode(['msg'=>"Permission Deny"]);
                    }else
                    {
                    	echo "Permission Deny!";
                    }
                    exit;
           		 }
        	}
     }

     /*
     * description：插入消息表（tb_message）
     *
     * persontype：0买家，1卖家
     * mtype：2：提现，3：充值，4：产品
     */
     public function insert_message($personid, $persontype, $mtype, $mcontent, $isread = 0) {
         $mcontent_tmp = strtoupper(PHP_OS)=='WINNT'?$mcontent:mb_convert_encoding($mcontent, 'GB2312', 'utf-8');
         $ret = DB::connection('flowtransaction')->table('tb_message')
         ->insert(['personid'=>$personid,'persontype'=>$persontype,'mtype'=>$mtype,'mcontent'=>$mcontent_tmp,'createtime'=>date("Y-m-d H:i:s"),'isread'=>$isread]);
         if ($ret != 1) {
             return false;
         }

         return true;
     }
}
