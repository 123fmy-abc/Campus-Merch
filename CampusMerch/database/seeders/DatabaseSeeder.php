<?php

namespace database\seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'account' => '25010420511',
                'name'    => '张梓潼',
                'phone'=>'19150480731',
                'email'   => '2704868796@qq.com',
                'email_verified_at' => now(),
                'password'=> Hash::make('zzt123456'),
                'default_address'=>'成都东软学院',
                'role'    => 'admin',

            ],
            [
                'account' => '25010420522',
                'name'    => '柴国继',
                'phone'=>'19150480732',
                'email'   => '2835129893@qq.com',
                'email_verified_at' => now(),
                'password'=> Hash::make('cgj123456'),
                'default_address'=>'成都东软学院',
                'role'    => 'admin',

            ],
            [
                'account' => '25010420533',
                'name'    => '伏明月',
                'phone'=>'19150480733',
                'email'   => '3227605507@qq.com',
                'email_verified_at' => now(),
                'password'=> Hash::make('fmy123456'),
                'default_address'=>'成都东软学院',
                'role'    => 'admin',

            ],
            [
                'account' => '25010420544',
                'name'    => '唐新雨',
                'phone'=>'19150480734',
                'email'   => '972536599@qq.com',
                'email_verified_at' => now(),
                'password'=> Hash::make('txy123456'),
                'default_address'=>'成都东软学院',
                'role'    => 'admin',
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'account'           => $userData['account'],
                    'name'              => $userData['name'],
                    'phone'             => $userData['phone'],
                    'email'             => $userData['email'],
                    'email_verified_at' => $userData['email_verified_at'],
                    'password'          => $userData['password'],
                    'default_address'   => $userData['default_address'],
                    'role'              => $userData['role'],
                ]
            );
        }
    }
}
