<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;

class MobileRuntimeConfigurationTest extends TestCase {
    private function configured(array $values,callable $check): void {
        $before=[];
        foreach($values as $key=>$value) { $before[$key]=[array_key_exists($key,$_ENV),$_ENV[$key]??null,array_key_exists($key,$_SERVER),$_SERVER[$key]??null]; $_ENV[$key]=$value; $_SERVER[$key]=$value; }
        try { $check(); } finally {
            foreach($before as $key=>[$hadEnv,$env,$hadServer,$server]) {
                if($hadEnv) $_ENV[$key]=$env; else unset($_ENV[$key]); if($hadServer) $_SERVER[$key]=$server; else unset($_SERVER[$key]);
            }
        }
    }
    public function test_keybox_configuration_is_operable_but_invalid_flags_and_allowlists_stay_closed(): void {
        $this->configured(['CLUBUNIO_KEYBOX_ADMINISTRATION_ENABLED'=>'true','CLUBUNIO_KEYBOX_ISSUANCE_ENABLED'=>'true','CLUBUNIO_KEYBOX_PROVIDER_ENABLED'=>'false',
            'CLUBUNIO_KEYBOX_OPERATIONS_REVIEWED'=>'invalid','CLUBUNIO_KEYBOX_TENANT_SLUGS'=>'synthetic-club','CLUBUNIO_KEYBOX_APPROVED_IDS'=>'{"synthetic-club":[3,7]}'],function () {
            $config=require dirname(__DIR__,2).'/config/clubunio-keybox.php';
            $this->assertTrue($config['administration_enabled']); $this->assertTrue($config['issuance_enabled']); $this->assertFalse($config['provider_enabled']);
            $this->assertFalse($config['operations_reviewed']); $this->assertSame(['synthetic-club'=>$config['approved_keybox_ids']['synthetic-club']],$config['approved_keybox_ids']);
            $this->assertSame([3,7],$config['approved_keybox_ids']['synthetic-club']);
        });
        $this->configured(['CLUBUNIO_KEYBOX_APPROVED_IDS'=>'{"synthetic-club":["3"]}','CLUBUNIO_KEYBOX_TENANT_SLUGS'=>'synthetic-club,https://foreign.invalid'],function () {
            $config=require dirname(__DIR__,2).'/config/clubunio-keybox.php'; $this->assertSame([],$config['approved_keybox_ids']); $this->assertSame([],$config['tenant_slugs']);
        });
    }
    public function test_bootstrap_operator_configuration_requires_exact_https_origins_and_explicit_booleans(): void {
        $this->configured(['CLUBUNIO_MOBILE_BOOTSTRAP_ENABLED'=>'true','CLUBUNIO_MOBILE_BOOTSTRAP_ORIGINS'=>'https://synthetic.example,https://custom.example:8443',
            'CLUBUNIO_MOBILE_MAINTENANCE_MODE'=>'false'],function () {
            $config=require dirname(__DIR__,2).'/config/clubunio-bootstrap.php'; $this->assertTrue($config['enabled']); $this->assertFalse($config['maintenance_mode']);
            $this->assertSame(['https://synthetic.example','https://custom.example:8443'],$config['origins']);
        });
        $this->configured(['CLUBUNIO_MOBILE_BOOTSTRAP_ENABLED'=>'unknown','CLUBUNIO_MOBILE_BOOTSTRAP_ORIGINS'=>'https://synthetic.example/path'],function () {
            $config=require dirname(__DIR__,2).'/config/clubunio-bootstrap.php'; $this->assertFalse($config['enabled']); $this->assertSame([],$config['origins']);
        });
    }
}
