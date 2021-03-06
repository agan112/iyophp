<?php namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use MyRedis;
use Log;
use DB;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Illuminate\Log\Writer;
use Illuminate\Database\Eloquent\SoftDeletes;

class IyoTopic extends Model {

	use SoftDeletes;
	protected $dates = ['deleted_at'];

	const PREFIX="topic";

	public static $attrnames = array(
		array("cache"=>"tid", "db"=> "id", "return"=>"tid"),
		array("cache"=>"title", "db"=> "title", "return"=>"title"),
		array("cache"=>"from", "db"=> "from", "return"=>"from"),
		array("cache"=>"image", "db"=> "image", "return"=>"image"),
		array("cache"=>"content", "db"=> "body", "return"=>"content"),
		array("cache"=>"uid", "db"=> "uid", "return"=>"uid"),
		array("cache"=>"numOfReplay", "db"=> "reply_count", "return"=>"numOfReplay"),
		array("cache"=>"numOfView", "db"=> "view_count", "return"=>"numOfView"),
		array("cache"=>"numOfLike", "db"=> "like_count", "return"=>"numOfLike"),
		array("cache"=>"abstract", "db"=> "abstract", "return"=>"abstract"),
		array("cache"=>"created_at", "db"=> "created_at", "return"=>"created_at"),
		array("cache"=>"numOfForward", "db"=> "forward_count", "return"=>"numOfForward"),
		array("cache"=>"allowedComment", "db"=> "allowed_comment", "return"=>"allowedComment"),
		array("cache"=>"deletedTimer", "db"=> "deleted_timer", "return"=>"deletedTimer"),
		array("cache"=>"pid", "db"=> "pid", "return"=>"pid"),
	);

	const TOPIC="topic:%s";
	const USTIMELINE = "user:%s:ustimeline";
	const SFTIMELINE = "user:%s:sftimeline";
	const USERTOPIC = "user:%s:topic";
	const USERMONTHTOPIC = "user:%s:month";
	const HOTTOPIC = "hot:topic";

	public static function converDateTime($value)
	{
		return date("Y年m月d日", strtotime($value));
	}

	public static function saveOrUpdate($title, $abstract, $from, $image, $uid, $body, $tid=0, $pid=0, $allowedComment=1, $deletedTimer=NULL)
	{
		Log::info("tid: ".$tid." pid: ".$pid." title: ".$title);

		if( $tid == 0 ) {
			$topic = new IyoTopic();
		} else {
			$topic = IyoTopic::find($tid);
		}

		$topic->title = $title;
		$topic->abstract = $abstract;
		$topic->from = $from;
		$topic->image = $image;
		$topic->uid = $uid;
		$topic->body = $body;
		$topic->pid = $pid;
		$topic->allowed_comment = $allowedComment;
		$topic->deleted_timer = $deletedTimer;

		$topic->save();

		$redis = MyRedis::connection("default");
		$key = sprintf(IyoTopic::USERTOPIC, $uid);
		$redis->zadd($key,strtotime($topic["created_at"]),$topic['id']);

		IyoTopic::reloadCache($tid);
		$topic = IyoTopic::queryById($tid);

		return $topic;
	}

	public static function destroy($id)
	{
		$topic = IyoTopic::find($id);
		if( !is_null($topic) ) {
			$topic->delete();
		}
		IyoTopic::cleanCache($id);
	}

	public static function reloadCache($id) {
		IyoTopic::cleanCache($id);
		IyoTopic::loadDataInToCache($id);
	}

	public static function loadDataInToCache($id) {
		Log::info("IyoTopic loadDataInToCache enter");
		$redis = MyRedis::connection("default");
		$dbtopic = IyoTopic::find($id);
		if( is_null($dbtopic) ) return;
		$key = sprintf(IyoTopic::TOPIC, $id);
		foreach( self::$attrnames as $attrname ) {
			Log::info( "attribute is ".$attrname["cache"]." ".$attrname["db"]." ".$dbtopic[$attrname["db"]] );
			$redis->hmset($key, $attrname["cache"], $dbtopic[$attrname["db"]]);
		}
	}

	public static function cleanCache($id) {
		Log::info("IyoTopic cleanCache enter");
		$redis = MyRedis::connection("default");
		$key = sprintf(IyoTopic::TOPIC, $id);
		$redis->del($key);
	}

	public static function queryById($id)
	{
		$redis = MyRedis::connection("default");
		$key = sprintf(IyoTopic::TOPIC, $id);

		if( !$redis->exists($key) ) {
			IyoTopic::reloadCache($id);
		}

		if( !$redis->exists($key) ) {
			return null;
		}

		$topic = [];
		foreach( self::$attrnames as $attrname ) {
			$topic[$attrname["return"]] = $redis->hget($key, $attrname["cache"]);
			if( $attrname["cache"] == "created_at" ) {
				$topic["created_at"] = date("Y年m月d日", strtotime($topic["created_at"]));
			}
			Log::info( "attribute is ".$attrname["return"]." ".$attrname["cache"]." ".$topic[$attrname["return"]]);
		}
		return $topic;
	}

	public static function queryTopicIdsByTime($uid, $uslist, $type, $num=0, $current=0)
	{
		$redis = MyRedis::connection("default");

		if( $type == "USTIMELINE" ) {
			$key = sprintf(IyoTopic::USTIMELINE, $uid);
		} else {
			$key = sprintf(IyoTopic::SFTIMELINE, $uid);
		}

		if( $current == 0 ) {
			if( $redis->exists($key) ) {
				$redis->del($key);
			}
		}

		$uslist[] = $uid;
		$redisunion = [];
		$tlist = [];

		if( !$redis->exists($key) ) {
			foreach( $uslist as $fid ) {
				$userlist = sprintf(IyoTopic::USERTOPIC, $fid);
				if( !$redis->exists($userlist) ) {
					IyoTopic::queryTopicIdsByUser($fid);
				}
				if( $redis->exists($userlist) ) {
					$redisunion[] = $userlist;
				}
			}
			//array_unshift($redisunion, count($redisunion));
			//Log::info("json value is ".json_encode($redisunion));
			//$redis->command('zunionstore', $key, $redisunion);
			//$redis->command($redisunion);


			if( count($redisunion) > 0 ) {
				$redis->zunionstore($key, 1, $redisunion[0]);
				for( $i = 1; $i<count($redisunion); $i++ ) {
					$redis->zunionstore($key, 2, $key, $redisunion[$i], "AGGREGATE", "MIN");
				}
			}
		}

		if( $redis->exists($key) ) {
			$tlist = $redis->zrevrange($key, $current, $current+$num-1);
		}

		return $tlist;
	}

	public static function queryTopicIdsByUser($uid, $num=0, $current=0)
	{
		$redis = MyRedis::connection("default");

		$key = sprintf(IyoTopic::USERTOPIC, $uid);
		if(!$redis->exists($key)) {
			$list = IyoTopic::where('uid', $uid)->orderBy('created_at', 'asc')
				->get(["id", "created_at"]);
			foreach( $list as $tid ) {
				$redis->zadd($key,strtotime($tid["created_at"]),$tid['id']);
			}
		}

		$tlist = [];
		if( $redis->exists($key) ) {
			$tlist = $redis->zrevrange($key, $current, $current+$num-1);
		}

		return $tlist;
	}

	public static function queryTopicIdsByMonthUser($uid, $num=0, $current=0)
	{
		$redis = MyRedis::connection("default");

		$key = sprintf(IyoTopic::USERMONTHTOPIC, $uid);
		if(!$redis->exists($key)) {
			$list = IyoTopic::where('uid', $uid)->orderBy('created_at', 'asc')
				->get(["id", "created_at"]);
			foreach( $list as $tid ) {
				$redis->zadd($key,strtotime($tid["created_at"]),$tid['id']);
			}
		}

		$tlist = [];
		if( $redis->exists($key) ) {
			$tlist = $redis->zrevrange($key, $current, $current+$num-1);
		}

		return $tlist;
	}


	public static function queryHotTopicIds($union_ids, $num=0, $current=0)
	{
		$redis = MyRedis::connection("default");

		if(!$redis->exists(IyoTopic::HOTTOPIC)) {
			$list = [];
			$list = IyoTopic::where('created_at', '>', time()-10*24*60*60)
				->whereIn("uid", $union_ids)->orderBy('view_count')->take(200)->get(["id", "view_count"]);
			foreach( $list as $tid ) {
				$redis->zadd(IyoTopic::HOTTOPIC,$tid["view_count"],$tid['id']);
			}
			$redis->pexpire(IyoTopic::HOTTOPIC, 24*60*60);
		}

		$tlist = [];
		if( $redis->exists(IyoTopic::HOTTOPIC) ) {
			$tlist = $redis->zrevrange(IyoTopic::HOTTOPIC, $current, $current+$num-1);
		}
		return $tlist;
	}

	public static function incrValue($key, $id, $field, $cfield) {
		DB::table('iyo_topics')->where('id', $id)->increment($field);
		$redis = MyRedis::connection("default");
		if( $redis->exists($key) ) {
			$redis->hincrby($key, $cfield, 1);
		}
	}

	public static function decrValue($key, $id, $field, $cfield) {
		DB::table('iyo_topics')->where('id', $id)->decrement($field);
		$redis = MyRedis::connection("default");
		if( $redis->exists($key) ) {
			$redis->hincrby($key, $cfield, -1);
		}
	}

	public static function incrNumOfView($id) {
		IyoTopic::incrValue(IyoTopic::PREFIX.":$id", $id, "view_count", "numOfView");
	}

	public static function incrNumOfForward($id) {
		IyoTopic::incrValue(IyoTopic::PREFIX.":$id", $id, "forward_count", "numOfForward");
	}

	public static function decrNumOfForward($id) {
		IyoTopic::decrValue(IyoTopic::PREFIX.":$id", $id, "forward_count", "numOfForward");
	}

	public static function incrNumOfLike($id) {
		IyoTopic::incrValue(IyoTopic::PREFIX.":$id", $id, "like_count", "numOfLike");
	}

	public static function decrNumOfLike($id) {
		IyoTopic::decrValue(IyoTopic::PREFIX.":$id", $id, "like_count", "numOfLike");
	}

	public static function incrNumOfReply($id) {
		IyoTopic::incrValue(IyoTopic::PREFIX.":$id", $id, "reply_count", "numOfReply");
	}

	public static function decrNumOfReply($id) {
		IyoTopic::decrValue(IyoTopic::PREFIX.":$id", $id, "reply_count", "numOfReply");
	}
}
