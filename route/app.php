<?php
use think\facade\Route;
Route::get('health',function(){return json(['status'=>'ok']);});
Route::group('api/v1',function(){
    Route::get('passenger-documents','Passenger/index');
    Route::post('passenger-documents','Passenger/create');
    Route::get('passenger-documents/:id','Passenger/show');
    Route::put('passenger-documents/:id','Passenger/update');
    Route::get('passenger-documents/:id/history','Passenger/history');
    Route::get('depots','Api/depots');
    Route::get('trips','Api/trips');
    Route::get('stations','Api/stations');
    Route::get('journeys','Api/journeys');
    Route::get('trips/:id','Api/trip');
    Route::post('tickets','Api/issue');
    Route::get('tickets/:number','Api/ticket');
    Route::post('tickets/:number/refund','Api/refund');
 })->middleware([\app\middleware\ApiAuth::class,\app\middleware\AgencyOrAdmin::class]);
Route::get('api/v1/me','Api/me')->middleware(\app\middleware\ApiAuth::class);
Route::group('api/v1/operator',function(){
    Route::get('tickets','OperatorTicket/index');
    Route::get('tickets/:number','OperatorTicket/show');
    Route::get('tickets/:number/tlv','OperatorTicket/entries');
    Route::post('tickets/:number/tlv','OperatorTicket/append');
    Route::delete('tickets/:number/tlv/:id','OperatorTicket/revoke');
    Route::get('tickets/:number/tlv-stream','OperatorTicket/stream');
    Route::get('tickets/:number/tlv/:id/history','OperatorTicket/history');
})->middleware([\app\middleware\ApiAuth::class,\app\middleware\OperatorOnly::class]);

Route::get('/',function(){return redirect('/test.html');});
Route::group('api/v1/admin',function(){
    Route::post('clear-date','Management/clearDate');
    Route::get('operators','Management/operators');
    Route::post('operators','Management/createOperator');
    Route::put('operators/:code','Management/updateOperator');
    Route::post('operators/:code/key','Management/rotateOperator');
    Route::get('operators/:code/agencies','Management/whitelist');
    Route::post('operators/:code/agencies','Management/addWhitelist');
    Route::delete('operators/:code/agencies/:agency','Management/removeWhitelist');
    Route::put('depots/operator','Management/assignDepot');
    Route::get('agencies','Management/agencies');
    Route::post('agencies','Management/createAgency');
    Route::put('agencies/:code','Management/updateAgency');
    Route::put('agencies/:code/operators','Management/grants');
    Route::post('agencies/:code/key','Management/rotate');
    Route::put('trips/:id/operator','Management/assign');
})->middleware([\app\middleware\ApiAuth::class,\app\middleware\AdminOnly::class]);





