<?php
/**
 * LRU 缓存单元测试
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use LRUCache;
use MultiLevelCache;

class LRUCacheTest extends TestCase
{
    private LRUCache $cache;
    
    protected function setUp(): void
    {
        $this->cache = new LRUCache(5); // 容量为5的缓存
    }
    
    protected function tearDown(): void
    {
        $this->cache->flush();
    }
    
    // ==================== 基本操作测试 ====================
    
    public function testSetAndGet(): void
    {
        $this->cache->set('key1', 'value1');
        $this->assertEquals('value1', $this->cache->get('key1'));
    }
    
    public function testGetNonExistent(): void
    {
        $this->assertNull($this->cache->get('non_existent'));
        $this->assertEquals('default', $this->cache->get('non_existent', 'default'));
    }
    
    public function testDelete(): void
    {
        $this->cache->set('key1', 'value1');
        $this->assertTrue($this->cache->delete('key1'));
        $this->assertNull($this->cache->get('key1'));
        $this->assertFalse($this->cache->delete('key1')); // 已删除
    }
    
    public function testHas(): void
    {
        $this->cache->set('key1', 'value1');
        $this->assertTrue($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
    }
    
    // ==================== LRU 淘汰测试 ====================
    
    public function testLRUEviction(): void
    {
        // 填满缓存
        for ($i = 1; $i <= 5; $i++) {
            $this->cache->set("key{$i}", "value{$i}");
        }
        
        $this->assertEquals(5, $this->cache->size());
        
        // 添加新项，应该淘汰最久未使用的 key1
        $this->cache->set('key6', 'value6');
        
        $this->assertEquals(5, $this->cache->size());
        $this->assertNull($this->cache->get('key1')); // key1 被淘汰
        $this->assertEquals('value6', $this->cache->get('key6'));
    }
    
    public function testLRUAccessUpdatesOrder(): void
    {
        // 填满缓存
        for ($i = 1; $i <= 5; $i++) {
            $this->cache->set("key{$i}", "value{$i}");
        }
        
        // 访问 key1，使其变为最近使用
        $this->cache->get('key1');
        
        // 添加新项，应该淘汰 key2（现在最久未使用）
        $this->cache->set('key6', 'value6');
        
        $this->assertEquals('value1', $this->cache->get('key1')); // key1 仍存在
        $this->assertNull($this->cache->get('key2')); // key2 被淘汰
    }
    
    // ==================== TTL 过期测试 ====================
    
    public function testExpiration(): void
    {
        $this->cache->set('key1', 'value1', 1); // 1秒过期
        
        $this->assertEquals('value1', $this->cache->get('key1'));
        
        sleep(2); // 等待过期
        
        $this->assertNull($this->cache->get('key1'));
    }
    
    // ==================== Remember 测试 ====================
    
    public function testRemember(): void
    {
        $callCount = 0;
        $callback = function() use (&$callCount) {
            $callCount++;
            return 'computed_value';
        };
        
        // 第一次调用，执行回调
        $result1 = $this->cache->remember('key1', $callback, 60);
        $this->assertEquals('computed_value', $result1);
        $this->assertEquals(1, $callCount);
        
        // 第二次调用，使用缓存
        $result2 = $this->cache->remember('key1', $callback, 60);
        $this->assertEquals('computed_value', $result2);
        $this->assertEquals(1, $callCount); // 回调未被再次调用
    }
    
    // ==================== 批量操作测试 ====================
    
    public function testMget(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        
        $results = $this->cache->mget(['key1', 'key2', 'key3']);
        
        $this->assertEquals('value1', $results['key1']);
        $this->assertEquals('value2', $results['key2']);
        $this->assertNull($results['key3']);
    }
    
    public function testMset(): void
    {
        $this->cache->mset([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3'
        ]);
        
        $this->assertEquals('value1', $this->cache->get('key1'));
        $this->assertEquals('value2', $this->cache->get('key2'));
        $this->assertEquals('value3', $this->cache->get('key3'));
    }
    
    // ==================== 统计测试 ====================
    
    public function testStats(): void
    {
        $this->cache->set('key1', 'value1');
        
        // 命中
        $this->cache->get('key1');
        $this->cache->get('key1');
        
        // 未命中
        $this->cache->get('key2');
        
        $stats = $this->cache->stats();
        
        $this->assertEquals(1, $stats['size']);
        $this->assertEquals(2, $stats['hits']);
        $this->assertEquals(1, $stats['misses']);
        $this->assertEquals(66.67, $stats['hit_rate']);
    }
    
    // ==================== GC 测试 ====================
    
    public function testGarbageCollection(): void
    {
        $this->cache->set('key1', 'value1', 1);
        $this->cache->set('key2', 'value2', 100);
        
        sleep(2); // 等待 key1 过期
        
        $gcCount = $this->cache->gc();
        
        $this->assertEquals(1, $gcCount);
        $this->assertFalse($this->cache->has('key1'));
        $this->assertTrue($this->cache->has('key2'));
    }
}
