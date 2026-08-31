<?php

use App\Livewire\EdboOptimizer;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
| 工作台路由：首页即优化器，另含使用指南页。
| 资深开发备注：业务逻辑全部下沉到 Livewire 组件与 Service，路由保持极薄。
*/

Route::get('/', EdboOptimizer::class)->name('edbo.index');

Route::view('/docs', 'edbo.docs')->name('edbo.docs');
