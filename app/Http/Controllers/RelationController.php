<?php namespace App\Http\Controllers;

use App\Http\Requests;
use App\Http\Controllers\Controller;

use App\Model\IyoRelation;
use App\Model\IyoUser;
use MyRedis;

use Illuminate\Http\Request;

class RelationController extends Controller {

	public function subscribe(Request $request)
	{
		$result = array('code' => trans('code.success'),'desc' => __LINE__,
			'message' => trans('successmsg.FollowSuccess'));
		
		$id = $request["id"];
		$fid = $request->json("fid",0);

		if( IyoRelation::checkIfFollow($id, $fid) ) {
			$result = array('code' => trans('code.RelationAlreadyExistsError'),'desc' => __LINE__,
				'message' => '用户已关注');
			return $result;
		}

		$user = IyoUser::queryById($fid);

		IyoRelation::add($id, $fid);
		IyoUser::increaseNumOfFollow($fid);

		return $result;
	}

	public function cancel(Request $request)
	{
		$result = array('code' => trans('code.success'),'desc' => __LINE__,
			'message' => '取消关注成功');
	
		$id = $request["id"];
		$fid = $request->json("fid",0);

		if( !IyoRelation::checkIfFollow($id, $fid) ) {
			$result = array('code' => trans('code.RelationNotExistsError'),'desc' => __LINE__,
				'message' => '用户未关注');
			return $result;
		}

		$user = IyoUser::queryById($fid);
		IyoRelation::del($id, $fid);

		IyoUser::decreaseNumOfFollow($fid);
		return $result;
	}

	public function queryFollowUnion(Request $request)
	{
		$response = array('code' => trans('code.success'),'desc' => __LINE__,
			'message' => '获取成功');

		$id = $request["id"];
		$num = $request->json("num",0);
		$current = $request->json("current",0);

		$ids = IyoRelation::queryFollowingList($request["id"]);
		$unions = [];

		foreach( $ids as $fid ) {
			$user = IyoUser::queryById($fid);
			if( $user["type"] == "2" ) { 
				$unions[] = $user;
			}
		}

		$rec_unionids = IyoUser::queryListByType(2);
		$rec_unions = [];
		foreach ($rec_unionids as $uid) {
			$rec_union = IyoUser::queryById($uid);
			if( $rec_union["recommend"] == 1 ) {
				$rec_unions[] = $rec_union;
			}
			if( count($rec_unions) >= 2 ) {
				break;
			}
		}

		$result["follow"] = $unions;
		$result["recommend"] = $rec_unions;

		$response["result"] = $result;
		return $response;
	}
}
