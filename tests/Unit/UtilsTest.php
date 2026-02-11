<?php
/**
 * Utils 工具类单元测试
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utils;

class UtilsTest extends TestCase
{
    // ==================== IP 相关测试 ====================
    
    public function testIsValidIp(): void
    {
        $this->assertTrue(Utils::isValidIp('192.168.1.1'));
        $this->assertTrue(Utils::isValidIp('8.8.8.8'));
        $this->assertTrue(Utils::isValidIp('::1'));
        $this->assertFalse(Utils::isValidIp('invalid'));
        $this->assertFalse(Utils::isValidIp('256.256.256.256'));
    }
    
    public function testIsLocalIp(): void
    {
        $this->assertTrue(Utils::isLocalIp('127.0.0.1'));
        $this->assertTrue(Utils::isLocalIp('192.168.1.1'));
        $this->assertTrue(Utils::isLocalIp('10.0.0.1'));
        $this->assertTrue(Utils::isLocalIp('::1'));
        $this->assertFalse(Utils::isLocalIp('8.8.8.8'));
        $this->assertFalse(Utils::isLocalIp('114.114.114.114'));
    }
    
    public function testMaskIp(): void
    {
        $this->assertEquals('192.168.1.***', Utils::maskIp('192.168.1.100'));
        $this->assertEquals('8.8.8.***', Utils::maskIp('8.8.8.8'));
    }
    
    // ==================== URL 处理测试 ====================
    
    public function testAutoCompleteUrl(): void
    {
        $this->assertEquals('https://example.com', Utils::autoCompleteUrl('example.com'));
        $this->assertEquals('https://example.com', Utils::autoCompleteUrl('https://example.com'));
        $this->assertEquals('http://example.com', Utils::autoCompleteUrl('http://example.com'));
        $this->assertEquals('', Utils::autoCompleteUrl(''));
    }
    
    public function testIsValidUrl(): void
    {
        $this->assertTrue(Utils::isValidUrl('https://example.com'));
        $this->assertTrue(Utils::isValidUrl('http://localhost:8080'));
        $this->assertFalse(Utils::isValidUrl('not-a-url'));
        $this->assertFalse(Utils::isValidUrl(''));
    }
    
    public function testExtractDomain(): void
    {
        $this->assertEquals('example.com', Utils::extractDomain('https://example.com/path'));
        $this->assertEquals('sub.example.com', Utils::extractDomain('https://sub.example.com'));
        $this->assertNull(Utils::extractDomain('invalid'));
    }
    
    // ==================== 字符串处理测试 ====================
    
    public function testRandomString(): void
    {
        $str1 = Utils::randomString(16);
        $str2 = Utils::randomString(16);
        
        $this->assertEquals(16, strlen($str1));
        $this->assertEquals(16, strlen($str2));
        $this->assertNotEquals($str1, $str2);
    }
    
    public function testUuid(): void
    {
        $uuid1 = Utils::uuid();
        $uuid2 = Utils::uuid();
        
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid1
        );
        $this->assertNotEquals($uuid1, $uuid2);
    }
    
    public function testMaskSensitive(): void
    {
        $this->assertEquals('abc***xyz', Utils::maskSensitive('abcdefghixyz'));
        $this->assertEquals('***', Utils::maskSensitive('ab'));
        $this->assertEquals('a***b', Utils::maskSensitive('a12345b', 1, 1));
    }
    
    // ==================== 安全相关测试 ====================
    
    public function testEscapeHtml(): void
    {
        $this->assertEquals('&lt;script&gt;', Utils::escapeHtml('<script>'));
        $this->assertEquals('&quot;test&quot;', Utils::escapeHtml('"test"'));
        $this->assertEquals('', Utils::escapeHtml(null));
    }
    
    public function testEscapeArray(): void
    {
        $input = [
            'name' => '<script>alert(1)</script>',
            'nested' => ['value' => '<b>test</b>']
        ];
        
        $result = Utils::escapeArray($input);
        
        $this->assertEquals('&lt;script&gt;alert(1)&lt;/script&gt;', $result['name']);
        $this->assertEquals('&lt;b&gt;test&lt;/b&gt;', $result['nested']['value']);
    }
    
    public function testHmacGeneration(): void
    {
        $data = 'test data';
        $secret = 'secret_key';
        
        $signature = Utils::generateHmac($data, $secret);
        
        $this->assertNotEmpty($signature);
        $this->assertTrue(Utils::verifyHmac($data, $signature, $secret));
        $this->assertFalse(Utils::verifyHmac($data, 'wrong_signature', $secret));
    }
    
    // ==================== JSON 处理测试 ====================
    
    public function testJsonEncode(): void
    {
        $data = ['name' => '测试', 'url' => 'https://example.com'];
        $json = Utils::jsonEncode($data);
        
        $this->assertStringContainsString('测试', $json);
        $this->assertStringContainsString('https://example.com', $json);
    }
    
    public function testJsonDecode(): void
    {
        $json = '{"name":"test","value":123}';
        $data = Utils::jsonDecode($json);
        
        $this->assertEquals('test', $data['name']);
        $this->assertEquals(123, $data['value']);
        
        // 无效JSON返回默认值
        $this->assertNull(Utils::jsonDecode('invalid'));
        $this->assertEquals([], Utils::jsonDecode('invalid', []));
    }
    
    // ==================== 时间处理测试 ====================
    
    public function testNowMs(): void
    {
        $now1 = Utils::nowMs();
        usleep(1000); // 1ms
        $now2 = Utils::nowMs();
        
        $this->assertGreaterThan($now1, $now2);
    }
    
    public function testFormatTimeDiff(): void
    {
        $this->assertEquals('30秒', Utils::formatTimeDiff(30));
        $this->assertEquals('5分钟', Utils::formatTimeDiff(300));
        $this->assertEquals('2小时', Utils::formatTimeDiff(7200));
        $this->assertEquals('3天', Utils::formatTimeDiff(259200));
    }
}
