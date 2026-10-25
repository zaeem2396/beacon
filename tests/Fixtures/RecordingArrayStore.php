<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Illuminate\Cache\ArrayStore;

/**
 * Array store that counts mutating calls. Reads are not counted.
 */
final class RecordingArrayStore extends ArrayStore
{
    public int $writes = 0;

    /**
     * @param string $key
     * @param int $seconds
     */
    public function put($key, $value, $seconds)
    {
        $this->writes++;

        return parent::put($key, $value, $seconds);
    }

    /**
     * @param string $key
     */
    public function increment($key, $value = 1)
    {
        $this->writes++;

        return parent::increment($key, $value);
    }

    /**
     * @param string $key
     */
    public function forget($key)
    {
        $this->writes++;

        return parent::forget($key);
    }

    public function flush()
    {
        $this->writes++;

        return parent::flush();
    }
}
