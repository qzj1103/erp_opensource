<?php
namespace eagle\modules\platform\controllers;

use eagle\models\assistant\DpEnable;
use yii\web\Controller;
use eagle\models\SaasAliexpressUser;
use yii\data\Sort;
use yii\data\Pagination;
use eagle\modules\util\helpers\TranslateHelper;
use eagle\models\SaasAliexpressAutosync;
use common\api\aliexpressinterfaceNew\AliexpressInterface_Auth;
use eagle\modules\app\apihelpers\AppTrackerApiHelper;
use eagle\models\QueueAliexpressGetorder;
use common\api\aliexpressinterface\AliexpressInterface_Helper;
use eagle\modules\message\apihelpers\MessageApiHelper;
use eagle\models\QueueAliexpressGetorder2;
use eagle\models\QueueAliexpressGetfinishorder;
use eagle\modules\platform\apihelpers\PlatformAccountApi;
class AliexpressAccountsNewController extends Controller
{
	public function behaviors() {
		return [
			'access' => [
				'class' => \yii\filters\AccessControl::className(),
				'rules' => [
					[
						'allow' => true,
						'roles' => ['@'],
					],
				],
			],
			'verbs' => [
				'class' => \yii\filters\VerbFilter::className(),
				'actions' => [
					'delete' => ['post'],
				],
			],
		];
	}
    
    /**
     * 授权第一步组织请求url向速卖通 提交
     */
    function actionAuth1() {
    	try{
	    	//速卖通账号主键
	    	$user_info = \Yii::$app->user->identity;
	    	if ($user_info['puid']==0){
	    		$uid = $user_info['uid'];
	    	}else {
	    		$uid = $user_info['puid'];
	    	}
	    	//app自定义参数，会原样返回，从而知道对应的账号
	    	$state = 'littleboss_'.$uid;
	    	//app的入口地址
	    	if(isset($_SERVER['HTTP_REFERER'])){
	    		$tempu = parse_url($_SERVER['HTTP_REFERER']);
	    	}else{
	    		$tempu = parse_url(\Yii::$app->request->hostInfo);
	    	}
	    	$host = $tempu['host'];
	    	//$host = 't15.littleboss.cn';
	    	$redirect_uri = 'http://'.$host.\Yii::$app->urlManager->getBaseUrl().'/'.'platform/aliexpress-accounts-new/auth2';
	    	$ApiAuth = new AliexpressInterface_Auth();

	    	$url = $ApiAuth->startAuthUrl($state, $redirect_uri);
	    	\Yii::$app->getResponse()->redirect($url);
    	}catch(\Exception $ex){
    		var_dump($ex->getMessage());return ;
    	}
    }
    
    /**
     * 授权第二步通过code获取访问令牌和长时令牌
     */
    function actionAuth2(){
    	set_time_limit(0);
    	
    	\Yii::info("AliexpressAccountsNewController: ".json_encode($_GET), "file");
    	//返回的code用于去长时令牌和访问令牌
    	$code = $_GET['code'];
    	$state = $_GET['state'];
    	$user_info = \Yii::$app->user->identity;
    	if ($user_info['puid']==0){
    		$uid = $user_info['uid'];
    	}else {
    		$uid = $user_info['puid'];
    	}
    	try{
    		if($state != 'littleboss_'.$uid){
    			throw new \Exception('验证错误，不是通过过小老板进行授权');
    		}elseif(empty($code)){
    			throw new \Exception('未取得 Code !');
    		}
    		//app的入口地址
    		$tempu = parse_url(\Yii::$app->request->hostInfo);
    		$host = $tempu['host'];
    		//$host = 't15.littleboss.cn';
    		$redirect_uri = 'http://'.$host.\Yii::$app->urlManager->getBaseUrl().'platform/AliexpressAccountsNew/Auth3';
    		$ApiAuth = new AliexpressInterface_Auth();
    		//给用户分配的开发者账号
	    	$dev_account = $ApiAuth->getDevAccount();

    		//使用code获取长时令牌和访问令牌
    		$d = $ApiAuth->getToken($code, $redirect_uri);
    		\Yii::info("AliexpressAccountsNewController: ".json_encode($d), "file");
    		if(isset($d['access_token'])){
//     			$timeout_str = substr($d['refresh_token_timeout'],0,8);
//     			$refresh_token_timeout = strtotime($timeout_str);
    			//$refresh_token_timeout = AliexpressInterface_Helper::transLaStrTimetoTimestamp($d['refresh_token_timeout']);
    			//保存信息 
    			/*$SAU_obj = SaasAliexpressUser::find()->where(['sellerloginid' => $d['user_nick']])->one();
    			if(!empty($SAU_obj)) {
	    			if($SAU_obj->uid != $uid){
	    				exit('<script type="text/javascript">window.opener.myreload('.json_encode(TranslateHelper::t('操作失败')).','.json_encode($d['resource_owner'].TranslateHelper::t('已被其他用户占用')).');window.close();</script>');
	    			}else{
	    				$SAU_obj->update_time = time();
	    				$SAU_obj->is_active = 1;
	    			}
    			}else{
    				$SAU_obj = new SaasAliexpressUser();
    				$SAU_obj->sellerloginid = $d['user_nick'];
    				$SAU_obj->uid = $uid;
    				$SAU_obj->create_time = time();
    				$SAU_obj->update_time = time();
    				$SAU_obj->is_active = 1;
    				$SAU_obj->addi_info = json_encode($d);
    			}
                //绑定分配的开发者账号
                $SAU_obj->app_key = $dev_account['app_key'];
                $SAU_obj->app_secret = $dev_account['app_secret'];

    			$SAU_obj->access_token = $d['access_token'];
    			$SAU_obj->refresh_token = $d['refresh_token'];
    			//$SAU_obj->access_token_timeout = time() + 28800; // 设置八小时过期，本来是10个小时过期
    			//$SAU_obj->refresh_token_timeout = $refresh_token_timeout; //一般长时令牌半年过期
    			$SAU_obj->save();
    			//绑定成功写入同步订单列表队列
    			//绑定成功写入同步订单列表队列
    			$types = array(
    				'day120',
    				'finish',
                    'finish30',
    				'time',
    				'onSelling',
    			);
    			foreach ($types as $type){
	    			$SAA_obj = SaasAliexpressAutosync::find()->where('sellerloginid=:sellerloginid and type=:type',array(':sellerloginid'=>$SAU_obj->sellerloginid,':type'=>$type))->one();
	    			if (isset($SAA_obj)){//已经有数据，只要更新
	    				$binding_time = $SAA_obj->binding_time;
	    				$status = $SAA_obj->status;
	    				$SAA_obj->is_active = $SAU_obj->is_active;
	    				$SAA_obj->status = 0;
	    				$SAA_obj->type=$type;
	    				$SAA_obj->times=0;
	    				$SAA_obj->binding_time=time();
				    	$SAA_obj->update_time = time();
				    	if ($type == 'finish'){
				    		if ($status == 4){
					    		$SAA_obj->start_time=$binding_time;
					    		$SAA_obj->end_time=0;
				    		}else{
				    			$SAA_obj->start_time=0;
				    			$SAA_obj->end_time=0;
				    		}
				    	}elseif ($type == 'time'){
				    		$SAA_obj->start_time=0;
				    		$SAA_obj->end_time=0;
				    	}
				    	$SAA_obj->save();
	    			}else{//新数据，插入一行数据
	    				$SAA_obj=new SaasAliexpressAutosync();
	    				$SAA_obj->uid = $uid;
	    				$SAA_obj->sellerloginid = $SAU_obj->sellerloginid;
	    				$SAA_obj->aliexpress_uid = $SAU_obj->aliexpress_uid;
	    				$SAA_obj->is_active = $SAU_obj->is_active;
	    				$SAA_obj->status = 0;
	    				$SAA_obj->type=$type;
	    				$SAA_obj->times=0;
	    				$SAA_obj->start_time=0;
	    				$SAA_obj->end_time=0;
	    				$SAA_obj->last_time=0;
	    				$SAA_obj->binding_time=time();
	    				$SAA_obj->create_time = time();
	    				$SAA_obj->update_time = time();
	    				$SAA_obj->save();
	    			}
    			}
    			//绑定账号时，将拉取站内信的app数据一并生成
    			$rtn = MessageApiHelper::setSaasMsgAutosync($uid, $SAU_obj->aliexpress_uid, $SAU_obj->sellerloginid, 'aliexpress');
    			
    			//绑定账号成功， 回调函数
    			PlatformAccountApi::callbackAfterRegisterAccount('aliexpress',$uid,['selleruserid'=>$SAU_obj->sellerloginid]);
    			if('fail' == $rtn['code']){
    				throw new \Exception('error:'.$rtn['message'],1);
    			}
    			*/
    			exit('<script type="text/javascript">window.opener.myreload('.json_encode(TranslateHelper::t('操作成功')).','.json_encode($d['user_nick'].TranslateHelper::t('绑定成功')).');window.close();</script>');
    		}elseif(isset($d['error'])){
    			throw new \Exception(print_r($d,1));
    		}else{
    			throw new \Exception('error:'.print_r($d,true));
    		}
    	}catch(\Exception $ex){
    		var_dump($ex->getMessage());die;
    	}
    	
    }
    /**
     * 授权第三步暂时没什么用只是第二步填入了一个app入口地址，请求成功之后会返回到这个地址
     * dzt 2015/03/03
     */
    function actionAuth3(){
    	echo 'SUCCESS';
    }
    
    /**
     * 解除绑定
     * dzt 2015/03/25 add for tracking 
     */
    public function actionDelete(){
    	return false;
    	$aliexpress_uid = trim($_POST['aliexpress_uid']);
    	$sellerloginid = trim($_POST['sellerloginid']);
    	
    	$User_obj = SaasAliexpressUser::find()->where('sellerloginid=:p and aliexpress_uid=:a', array(':p' => $sellerloginid,':a'=>$aliexpress_uid))->one();
    	$SAA_obj = SaasAliexpressAutosync::find()->where('sellerloginid=:p and aliexpress_uid=:a',array(':p' => $sellerloginid,':a'=>$aliexpress_uid))->one();
	    if($User_obj != null){
	    	if(!$User_obj->delete()){
	    		\Yii::trace("Platform,".__CLASS__.",". __FUNCTION__.",平台绑定错误日志： ".print_r($User_obj->getErrors(),true),"file");
	    		return json_encode(array("code"=>"fail","message"=>TranslateHelper::t('删除账号信息失败，请重试或联系客服')));
	    	}else{
	    		$saasId = \Yii::$app->user->identity->getParentUid();
	    		//删除 redis 同步 队列数据
	    		PlatformAccountApi::callbackAfterDeleteAccount('aliexpress', $saasId,['site_id'=>$aliexpress_uid, 'selleruserid'=>$sellerloginid]);
	    		//删除订单列表同步队列数据
	    		SaasAliexpressAutosync::deleteAll(['sellerloginid'=>$sellerloginid,'aliexpress_uid'=>$aliexpress_uid]);
	    		//删除订单详情同步队列数据
	    		QueueAliexpressGetorder::deleteAll(['sellerloginid'=>$sellerloginid,'aliexpress_uid'=>$aliexpress_uid]);
	    		
	    		QueueAliexpressGetorder2::deleteAll(['sellerloginid'=>$sellerloginid,'aliexpress_uid'=>$aliexpress_uid]);
	    		
	    		QueueAliexpressGetfinishorder::deleteAll(['sellerloginid'=>$sellerloginid,'aliexpress_uid'=>$aliexpress_uid]);
	    		//删除dp_enable中解绑数据，避免冗余。
	    		DpEnable::deleteAll(['dp_shop_id'=>$sellerloginid,'dp_puid'=>$User_obj->uid]);
	    		
	    		//删除消息同步记录	lzhl	2017-07-25
	    		MessageApiHelper::delSaasMsgAutosync($saasId, $aliexpress_uid, $sellerloginid, 'aliexpress');
	    	}
	    } 	
	    // 记录到  app_user_action_log  表
	    AppTrackerApiHelper::actionLog("Tracker","/platform/aliexpressaccounts/delete");
		
    	return json_encode(array("code"=>"ok","message"=>TranslateHelper::t('账号解除绑定成功')));
    }
    
    /**
     * 设置别名 页面显示
     * lkh 2016/06/15 
     */
    public function actionSetaliasbox(){
    	if (!empty($_REQUEST['uid'] )&& !empty( $_REQUEST['sellerid'])){
    		$account = SaasAliexpressUser::find()->where(['aliexpress_uid'=>$_REQUEST['uid'] , 'sellerloginid'=>$_REQUEST['sellerid']])->asArray()->one();
    		return $this->renderPartial('setalias', ['account'=>$account ]);
    	}else{
    		return TranslateHelper::t('找不到相关的账号信息');
    	}
    	
    }//end of actionSetaliasbox
    
    public function actionSaveAlias(){
    	if (!empty($_REQUEST['aliexpress_uid']) && !empty($_REQUEST['sellerloginid']) ){
    		$account = SaasAliexpressUser::find()->where(['aliexpress_uid'=>$_REQUEST['aliexpress_uid'] , 'sellerloginid'=>$_REQUEST['sellerloginid']])->one();
    		if ($account->store_name == $_REQUEST['store_name']) return json_encode(['success'=>false , 'message'=>TranslateHelper::t('别名已经是').$_REQUEST['store_name']]);
    		$account->store_name = $_REQUEST['store_name'];
    		if ($account->save()){
    			return json_encode(['success'=>true , 'message'=>'']);
    		}else{
    			$errors = $account->getErrors();
    			$msg = "";
    			foreach($errors as $row){
    				$msg .= $row;
    			}
    			
    			return json_encode(['success'=>false , 'message'=>$msg]);
    		}
    	}else{
    		return json_encode(['success'=>false , 'message'=>TranslateHelper::t('找不到相关的账号信息')]);
    	}
    	
    	
    	
    	
    }
    
 
}