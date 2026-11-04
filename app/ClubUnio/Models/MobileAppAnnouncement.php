<?php
namespace App\ClubUnio\Models;
class MobileAppAnnouncement extends CatalogModel {
    protected $dateFormat='Y-m-d H:i:s.uP';
    protected $casts=['active'=>'boolean','starts_at'=>'immutable_datetime','ends_at'=>'immutable_datetime','revision'=>'integer'];
}
