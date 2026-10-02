<?php

use App\Jobs\ReportHealth;
use Illuminate\Support\Facades\Schedule;

// Each queue proves it is being served; the Setup page reads the answers.
Schedule::job(new ReportHealth('default'))->everyMinute();
Schedule::job(new ReportHealth('agents'))->everyMinute();
