<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class PlanModuleCheck
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, $moduleName = null): Response
    {
        // $redirectToRoute = null;
        // if (! $request->user() ||
        //     ($request->user() instanceof MustVerifyEmail &&
        //     ! $request->user()->hasVerifiedEmail())) {
        //     return $request->expectsJson()
        //             ? response()->json([
        //                 'is_success' => false,
        //                 "msg" => __('Your email address is not verified'),
        //                 "data" => ['content' => null]
        //             ])
        //             : Redirect::guest(URL::route($redirectToRoute ?: 'verification.notice'));
        // }
       
        if (auth()->user() && auth()->user()->type != 'super admin')
        {
            if($moduleName != null)
            {
                $moduleName =  explode('-',$moduleName);
                $status = false;
                foreach($moduleName as $m)
                {
                    $status = module_is_active($m);
                    if($status == true)
                    {
                        break;
                    }
                }
                if($status == true)
                {
                    $active_module = ActivatedModule();
                    if(!empty(array_intersect($moduleName,$active_module)))
                    {
                        return $next($request);
                    }
                }

                // Check if the request expects JSON (AJAX)
                if ($request->expectsJson()) {
                    return response()->json([
                        'is_success' => false,
                        'msg' => __('Permission denied'),
                        'data' => ['content' => null],
                    ], 403);
                }

                return redirect()->route('dashboard')->with('error', __('Permission denied '));
            }
            else
            {
                // Check if the request expects JSON (AJAX)
                if ($request->expectsJson()) {
                    return response()->json([
                        'is_success' => false,
                        'msg' => __('Permission denied'),
                        'data' => ['content' => null],
                    ], 403);
                }

                return redirect()->route('dashboard')->with('error', __('Permission denied'));
            }
        }
        
        return $next($request);
    }
}
