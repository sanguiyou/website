<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Session;
use App\Permission;
use App\Role;
use App\User;
use Validator;
use Auth;
use Entrust;

class LoginMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
    	$userinfo = Session::get('userinfo');
    	if (empty($userinfo)) {
            $before = $_SERVER['REQUEST_URI'];
    		return redirect('/admin/login?before='.$before);
    	}

        return $next($request);
    }
}
