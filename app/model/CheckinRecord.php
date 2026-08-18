<?php
namespace app\model;
use think\Model;

class CheckinRecord extends Model
{
    protected $table = 'checkin_record';
    protected $autoWriteTimestamp = false;
}
