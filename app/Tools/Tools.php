<?php
namespace App\Tools;
use Illuminate\Support\Facades\DB;

class Tools {

	public static function curl_get($url, $timeout) {
		$ci = curl_init();
		curl_setopt($ci, CURLOPT_URL, $url);
		curl_setopt($ci, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ci, CURLOPT_CONNECTTIMEOUT, $timeout);
		#curl_setopt($ci, CURLOPT_USERAGENT, 'PHP/'.PHP_VERSION);
		curl_setopt($ci, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ci, CURLOPT_RETURNTRANSFER, 1);

		$ret = curl_exec($ci);
		$httpcode = curl_getinfo($ci, CURLINFO_HTTP_CODE);
		curl_close($ci);
		if ($httpcode >= 400) {
			return -1;
			//throw new Exception(sprintf("curl_get from %s return %d.", $url, $httpcode));
		}

		return $ret;
	}

	public static function curl_post($url, $data, $timeout) {
		$ci = curl_init();
		curl_setopt($ci, CURLOPT_URL, $url);
		curl_setopt($ci, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ci, CURLOPT_CONNECTTIMEOUT, $timeout);
		#curl_setopt($ci, CURLOPT_USERAGENT, 'PHP/'.PHP_VERSION);
		curl_setopt($ci, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ci, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ci, CURLOPT_POST,1);
		if (is_array($data)) {
			curl_setopt($ci, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		} else {
			curl_setopt($ci, CURLOPT_POSTFIELDS, $data);
		}

		$ret = curl_exec($ci);
		$httpcode = curl_getinfo($ci, CURLINFO_HTTP_CODE);
		curl_close($ci);
		if($httpcode >= 400) {
			return -1;
			//throw new Exception(sprintf("curl_post from %s return %d.", $url, $httpcode));
		}

		return $ret;
	}

	public static function addrdetailbyip($ip){
		$url = "http://api.map.baidu.com/location/ip?ak=8727cc0a4a1ba244e798b123cde5cc8e&ip=".$ip."&coor=bd09ll";
		$curlch = curl_post($url, 5);
		$resultData = json_decode($curlch,true);
		//$this -> metislogStrSwt .= ("[" . date("Y-m-d H:i:s", time()) . "][ip定位结果".json_encode($resultData)."......]\n");

		if($resultData["status"] ==0){
			$province =$resultData["content"]["address_detail"]["province"];
			$city =$resultData["content"]["address_detail"]["city"];
			/*
			 $district =$resultData["content"]["address_detail"]["district"];
			 $street =$resultData["content"]["address_detail"]["street"];
			 $street_number =$resultData["content"]["address_detail"]["street_number"];
			 */

			$y =$resultData["content"]["point"]["y"];
			$x =$resultData["content"]["point"]["x"];
			if($province=="北京市" || $province=="北京"){
				$addrdetail = $y.",".$x.",null,null,朝阳区,".$city.",".$province;
			}else if($province=="天津市" || $province=="天津"){
				$addrdetail = $y.",".$x.",null,null,和平区,".$city.",".$province;
			}else if($province=="上海市" || $province=="上海"){
				$addrdetail = $y.",".$x.",null,null,黄浦区,".$city.",".$province;
			}else if($province=="重庆市" || $province=="重庆"){
				$addrdetail = $y.",".$x.",null,null,江津,".$city.",".$province;
			}else{
				$addrdetail = $y.",".$x.",null,null,null,".$city.",".$province;
			}
		}else{
			return array("result" => false, "msg" => "IP定位失败！");
		}
		return  array("result" => true, "msg" => "","addrdetail"=>explode(",", $addrdetail));
	}



	public static function addrdetailListbyip($addrdetailList,$cityList){
		if (count($addrdetailList) == 7) {
			$cityname = $addrdetailList[6];
			$city = 0;
			$city2 = 0;
			foreach ($cityList as $cityObj) {
				if (strpos($cityname, $cityObj["name"]) !== false) {
					$city = $cityObj["id"];
					$city2name = $addrdetailList[5];
					$isExist = false;
					//有些[省直辖县级行政单位]取倒数第三块内容
					if (strpos($addrdetailList[5], "省直辖县级行政单位") !== false) {
						$city2name = $addrdetailList[4];
					}
					if (strpos($addrdetailList[5], "海南省直辖县级行政单位") !== false) {
						$city2name = "海口市";
					}
					//有些直辖市取倒数第三块内容
					if ($city == 1000 || $city == 2000 || $city == 3000 || $city == 33000 || $city == 34000 || $city == 36000) {
						$city2name = $addrdetailList[4];
					}

					if($city2name == "" || $city2name == null){
						$city2name = "无";//下面strpos() 为空的时候，出现提示错误
					}

					foreach ($cityObj["child"] as $city2obj) {
						if (strpos($city2obj["name"],$city2name) !== false || strpos($city2name,$city2obj["name"]) !== false) {
							$city2 = $city2obj["id"];
							$isExist = true;
							break;
						}
						if (isset($city2obj["name1"])) {
							if (strpos($city2obj["name1"],$city2name) !== false || strpos($city2name, $city2obj["name1"]) !== false) {
								$city2 = $city2obj["id"];
								$isExist = true;
								break;
							}
						}
					}
					break;
				}
			}
		} else {
			$returnObj = array("result" => false, "msg" => "地区格式不对");
			return $returnObj;
		}


		if ($city == 0) {
			$returnObj = array("result" => false, "msg" => "未找到相应地区");
			return $returnObj;
		}

		$returnObj = array("result" => true, "msg" => array("cityid" => $city, "city2id" => $city2));
		return $returnObj;
	}


	public static function getCityList() {
		$tab1 = DB::table('city')->get(['ID', 'name']);
		$tab2 = DB::table('city2')->get(['ID', 'name', 'parent']);
		$cityList = array();
		foreach ($tab1 as $row) {
			$id = (int)$row->ID;
			$cityList[$id] = array("id" => $id, "name" => $row->name, "child" => array());
		}

		foreach ($tab2 as $row) {
			$id = (int)$row->ID;
			$parent = (int)$row->parent;
			$cityList[$parent]["child"][$id] = array("id" => $id, "name" => $row->name);
		}
		return $cityList;
	}

	public static function utf8_to_gb2312($content) {
		return strtoupper(PHP_OS)=='WINNT'?$content:mb_convert_encoding($content, 'GB2312', 'utf-8');
	}

	public static function gb2312_to_utf8($content) {
		return strtoupper(PHP_OS)=='WINNT'?$content:mb_convert_encoding($content, 'utf-8', 'GB2312');
	}
}

?>
