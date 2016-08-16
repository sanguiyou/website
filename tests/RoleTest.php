<?php

use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
//use App\Permission;
//use App\Role;
use App\User;
//use Validator;
//use Auth;
//use Entrust;

class RoleTest extends TestCase
{
    /**
     * A basic functional test example.
     *
     * @return void
     */
    public function testRole()
    {
        $linkluck = User::where('name', '=', 'linkluck')->first();
        $this->assertEquals(True, $linkluck->hasRole('ad'));
        $this->assertEquals(False, $linkluck->hasRole('fi'));
    }

    public function testPermission()
    {
        $linkluck = User::where('name', '=', 'linkluck')->first();

        $this->assertEquals(True, $linkluck->can('examine'));
        $this->assertEquals(True, $linkluck->can('edit-user'));
    }
}
