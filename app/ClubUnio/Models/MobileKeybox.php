<?php
namespace App\ClubUnio\Models;
class MobileKeybox extends CatalogModel {
    public $timestamps=false;
    protected $casts=['active'=>'boolean','revision'=>'integer'];
    protected $hidden=['provider_device_cipher'];
    public function bus() { return $this->belongsTo(Bus::class); }
}
