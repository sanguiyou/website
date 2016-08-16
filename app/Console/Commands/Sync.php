<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use App\Tools\Tools;

class Sync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'flow:sync {flag} {date}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'sync flow day summary.
    flag: 0(buyer),1(saler);
    cdate: date (2015-12-12)';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $flag = $this->argument('flag');
        $date = $this->argument('date');
        $timeout = Config::get('eat.get_day_flow_summary_url_timeout');

        if ($flag != 0 && $flag != 1) {
            $this->error('flag: only 0/1');
            return;
        }
        if (!$this->validateDate($date, 'Y-m-d')) {
            $this->error('date: Y-m-d eg: 2016-01-01');
            return;
        }

        if ($flag == 0) {
            $url = Config::get('eat.buyer_day_flow_summary_url') . '?day=' . $date;
        } else {
            $url = Config::get('eat.saler_day_flow_summary_url') . '?day=' . $date;
        }
        $ret = Tools::curl_get($url, $timeout);
        if ($ret == -1) {
            $this->error('get ' . $url . ' timeout(' . $timeout . ').');
            return;
        }
        $data = json_decode($ret);
        if ($data->success == false) {
            $this->error('sync error. ' . $url . ' info:' . $ret);
            return;
        }
        $params = [];
        $useridlist = [];
        foreach ($data->data as $key => $value) {
            $params[$key] = [];
            $params[$key]['userid'] = $flag==0?$value->buyerid:$value->salerid;
            $params[$key]['spent'] = $value->spent;
            $params[$key]['chatcnt'] = $value->chatcnt;
            $params[$key]['bestchatcnt'] = $value->bestchatcnt;
            $params[$key]['flag'] = $flag;
            $params[$key]['stattime'] = $date;
            $useridlist[$key] = $flag==0?$value->buyerid:$value->salerid;

        }
        DB::connection('flowtransaction')->table('tb_flow_day_summary_log')
            ->whereIn('userid', $useridlist)->where('flag', $flag)->where('stattime', $date)->delete();
        DB::connection('flowtransaction')->table('tb_flow_day_summary_log')->insert($params);
    }

    private function validateDate($date, $format = 'Y-m-d H:i:s')
    {
        $unixTime=strtotime($date);
        $checkDate= date($format, $unixTime);
        $this->info($checkDate);
        if ($checkDate == $date) {
            return true;
        } else {
            return false;
        }
    }
}
