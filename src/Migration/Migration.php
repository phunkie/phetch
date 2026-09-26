<?php

namespace Phunkie\Phetch\Migration;

use Phunkie\Phetch\Query;

interface Migration
{
    /**
     * @return Query<mixed>
     */
    public function up(): Query;

    /**
     * @return Query<mixed>
     */
    public function down(): Query;
}
