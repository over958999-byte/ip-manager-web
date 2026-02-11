<?php
/**
 * 安全增强模块单元测试
 */

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecurityEnhanced;

class SecurityEnhancedTest extends TestCase
{
    private SecurityEnhanced $security;
    
    protected function setUp(): void
    {
        $this->security = SecurityEnhanced::getInstance();
    }
    
    // ==================== XSS 防护测试 ====================
    
    public function testEscapeHtml(): void
    {
        $this->assertEquals('&lt;script&gt;', $this->security->escapeHtml('<script>'));
        $this->assertEquals('&lt;img onerror=&quot;alert(1)&quot;&gt;', 
            $this->security->escapeHtml('<img onerror="alert(1)">'));
        $this->assertEquals('', $this->security->escapeHtml(null));
    }
    
    public function testEscapeArray(): void
    {
        $input = [
            'safe' => 'normal text',
            'unsafe' => '<script>alert("xss")</script>',
            'nested' => [
                'value' => '<b>bold</b>'
            ]
        ];
        
        $result = $this->security->escapeArray($input);
        
        $this->assertEquals('normal text', $result['safe']);
        $this->assertEquals('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $result['unsafe']);
        $this->assertEquals('&lt;b&gt;bold&lt;/b&gt;', $result['nested']['value']);
    }
    
    public function testEscapeJs(): void
    {
        $result = $this->security->escapeJs('</script><script>alert(1)');
        $this->assertStringNotContainsString('</script>', $result);
    }
    
    public function testSanitizeHtml(): void
    {
        $input = '<p>Hello</p><script>alert(1)</script><b>World</b>';
        $result = $this->security->sanitizeHtml($input);
        
        $this->assertStringContainsString('<p>Hello</p>', $result);
        $this->assertStringContainsString('<b>World</b>', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }
    
    // ==================== HMAC 签名测试 ====================
    
    public function testGenerateSignature(): void
    {
        $params = ['name' => 'test', 'value' => '123'];
        $timestamp = 1700000000;
        
        $signature1 = $this->security->generateSignature($params, $timestamp);
        $signature2 = $this->security->generateSignature($params, $timestamp);
        
        $this->assertEquals($signature1, $signature2); // 相同输入产生相同签名
        $this->assertEquals(64, strlen($signature1)); // SHA256 输出长度
    }
    
    public function testVerifySignature(): void
    {
        $params = ['name' => 'test', 'value' => '123'];
        $timestamp = time();
        
        $signature = $this->security->generateSignature($params, $timestamp);
        
        $this->assertTrue($this->security->verifySignature($params, $signature, $timestamp));
        $this->assertFalse($this->security->verifySignature($params, 'wrong_signature', $timestamp));
        
        // 修改参数后签名无效
        $params['name'] = 'modified';
        $this->assertFalse($this->security->verifySignature($params, $signature, $timestamp));
    }
    
    public function testSignRequest(): void
    {
        $data = ['action' => 'create', 'id' => 1];
        $signed = $this->security->signRequest($data);
        
        $this->assertArrayHasKey('timestamp', $signed);
        $this->assertArrayHasKey('signature', $signed);
        $this->assertEquals('create', $signed['action']);
    }
    
    // ==================== 加密解密测试 ====================
    
    public function testEncryptDecrypt(): void
    {
        $plaintext = '敏感数据 Sensitive Data 123!@#';
        
        $encrypted = $this->security->encrypt($plaintext);
        $this->assertNotEquals($plaintext, $encrypted);
        $this->assertNotNull($encrypted);
        
        $decrypted = $this->security->decrypt($encrypted);
        $this->assertEquals($plaintext, $decrypted);
    }
    
    public function testDecryptInvalid(): void
    {
        $this->assertNull($this->security->decrypt('invalid_data'));
        $this->assertNull($this->security->decrypt(''));
    }
    
    public function testEncryptConfig(): void
    {
        $config = [
            'username' => 'admin',
            'password' => 'secret123',
            'api_key' => 'key_abc',
            'normal_setting' => 'value'
        ];
        
        $encrypted = $this->security->encryptConfig($config);
        
        // 敏感字段被加密
        $this->assertStringStartsWith('ENC:', $encrypted['password']);
        $this->assertStringStartsWith('ENC:', $encrypted['api_key']);
        
        // 普通字段未加密
        $this->assertEquals('admin', $encrypted['username']);
        $this->assertEquals('value', $encrypted['normal_setting']);
        
        // 解密后恢复原值
        $decrypted = $this->security->decryptConfig($encrypted);
        $this->assertEquals('secret123', $decrypted['password']);
        $this->assertEquals('key_abc', $decrypted['api_key']);
    }
    
    // ==================== 日志脱敏测试 ====================
    
    public function testMaskIp(): void
    {
        $this->assertEquals('192.168.1.***', $this->security->maskIp('192.168.1.100'));
        $this->assertEquals('8.8.8.***', $this->security->maskIp('8.8.8.8'));
    }
    
    public function testMaskEmail(): void
    {
        // 'test' 长度4，保留首尾各1字符，中间2个*
        $this->assertEquals('t**t@example.com', $this->security->maskEmail('test@example.com'));
        $this->assertEquals('**@x.com', $this->security->maskEmail('ab@x.com'));
    }
    
    public function testMaskPhone(): void
    {
        $this->assertEquals('138****5678', $this->security->maskPhone('13812345678'));
        // 7位数字，按逻辑 strlen >= 7 走正常脱敏
        $this->assertEquals('123****4567', $this->security->maskPhone('1234567890'));
        // 6位数字，全部脱敏
        $this->assertEquals('******', $this->security->maskPhone('123456'));
    }
    
    public function testMaskLogData(): void
    {
        $data = [
            'user_id' => 123,
            'username' => 'admin',
            'password' => 'secret123',
            'client_ip' => '192.168.1.100',
            'email' => 'user@example.com',
            'phone' => '13812345678'
        ];
        
        $masked = $this->security->maskLogData($data);
        
        // 非敏感字段保持原样
        $this->assertEquals(123, $masked['user_id']);
        $this->assertEquals('admin', $masked['username']);
        
        // 敏感字段被脱敏
        $this->assertNotEquals('secret123', $masked['password']);
        $this->assertEquals('192.168.1.***', $masked['client_ip']);
        $this->assertStringContainsString('*', $masked['email']);
        $this->assertStringContainsString('****', $masked['phone']);
    }
    
    // ==================== SQL注入检测测试 ====================
    
    public function testDetectSqlInjection(): void
    {
        // 应该检测到的注入尝试
        $this->assertTrue($this->security->detectSqlInjection("1' OR '1'='1"));
        $this->assertTrue($this->security->detectSqlInjection("1; DROP TABLE users"));
        $this->assertTrue($this->security->detectSqlInjection("' UNION SELECT * FROM users--"));
        $this->assertTrue($this->security->detectSqlInjection("admin'--"));
        
        // 正常输入不应该被检测为注入
        $this->assertFalse($this->security->detectSqlInjection("normal user input"));
        $this->assertFalse($this->security->detectSqlInjection("john.doe@example.com"));
        $this->assertFalse($this->security->detectSqlInjection("123456"));
    }
    
    public function testSanitizeInput(): void
    {
        // 移除NULL字节
        $input = "test\x00value";
        $result = $this->security->sanitizeInput($input);
        $this->assertStringNotContainsString("\x00", $result);
        
        // 移除不可见控制字符
        $input = "test\x0Bvalue";
        $result = $this->security->sanitizeInput($input);
        $this->assertStringNotContainsString("\x0B", $result);
    }
    
    // ==================== CSP 策略测试 ====================
    
    public function testGetCspPolicy(): void
    {
        $policy = $this->security->getCspPolicy();
        
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("script-src", $policy);
        $this->assertStringContainsString("frame-ancestors", $policy);
    }
    
    public function testGetCspPolicyWithOptions(): void
    {
        $policy = $this->security->getCspPolicy([
            'script-src' => "'self' https://cdn.example.com"
        ]);
        
        $this->assertStringContainsString("https://cdn.example.com", $policy);
    }
}
