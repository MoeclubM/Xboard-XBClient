<?php

namespace Plugin\Xbclient\Models;

use Illuminate\Database\Eloquent\Model;

class XbclientRewardLog extends Model
{
    protected $table = 'v2_xbclient_reward_logs';
    protected $guarded = ['id'];
    protected $casts = [
        'rewarded_at' => 'timestamp',
    ];
}
