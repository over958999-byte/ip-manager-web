<?php
/**
 * 统一输入验证类
 */

class Validator {
    private $errors = [];
    private $data = [];
    
    public function __construct(array $data = []) {
        $this->data = $data;
    }
    
    /**
     * 设置验证数据
     */
    public function setData(array $data): self {
        $this->data = $data;
        $this->errors = [];
        return $this;
    }
    
    /**
     * 获取验证错误
     */
    public function getErrors(): array {
        return $this->errors;
    }
    
    /**
     * 获取第一个错误
     */
    public function getFirstError(): ?string {
        return $this->errors[0] ?? null;
    }
    
    /**
     * 是否验证通过
     */
    public function passes(): bool {
        return empty($this->errors);
    }
    
    /**
     * 是否验证失败
     */
    public function fails(): bool {
        return !empty($this->errors);
    }
    
    // ==================== 验证规则 ====================
    
    /**
     * 必填验证
     */
    public function required(string $field, string $message = ''): self {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            $this->errors[] = $message ?: "{$field} 不能为空";
        }
        return $this;
    }
    
    /**
     * IP 地址验证
     */
    public function ip(string $field, string $message = ''): self {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_IP)) {
            $this->errors[] = $message ?: "{$field} 不是有效的IP地址";
        }
        return $this;
    }
    
    /**
     * IPv4 地址验证
     */
    public function ipv4(string $field, string $message = ''): self {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->errors[] = $message ?: "{$field} 不是有效的IPv4地址";
        }
        return $this;
    }
    
    /**
     * URL 验证
     */
    public function url(string $field, string $message = ''): self {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
            $this->errors[] = $message ?: "{$field} 不是有效的URL";
        }
        return $this;
    }
    
    /**
     * 邮箱验证
     */
    public function email(string $field, string $message = ''): self {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = $message ?: "{$field} 不是有效的邮箱地址";
        }
        return $this;
    }
    
    /**
     * 最小长度验证
     */
    public function minLength(string $field, int $min, string $message = ''): self {
        $value = $this->data[$field] ?? '';
        if (mb_strlen($value) < $min) {
            $this->errors[] = $message ?: "{$field} 长度不能少于 {$min} 个字符";
        }
        return $this;
    }
    
    /**
     * 最大长度验证
     */
    public function maxLength(string $field, int $max, string $message = ''): self {
        $value = $this->data[$field] ?? '';
        if (mb_strlen($value) > $max) {
            $this->errors[] = $message ?: "{$field} 长度不能超过 {$max} 个字符";
        }
        return $this;
    }
    
    /**
     * 数值范围验证
     */
    public function between(string $field, $min, $max, string $message = ''): self {
        $value = $this->data[$field] ?? 0;
        if ($value < $min || $value > $max) {
            $this->errors[] = $message ?: "{$field} 必须在 {$min} 到 {$max} 之间";
        }
        return $this;
    }
    
    /**
     * 整数验证
     */
    public function integer(string $field, string $message = ''): self {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && !ctype_digit(strval($value))) {
            $this->errors[] = $message ?: "{$field} 必须是整数";
        }
        return $this;
    }
    
    /**
     * 数字验证（含小数）
     */
    public function numeric(string $field, string $message = ''): self {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && !is_numeric($value)) {
            $this->errors[] = $message ?: "{$field} 必须是数字";
        }
        return $this;
    }
    
    /**
     * 数组验证
     */
    public function isArray(string $field, string $message = ''): self {
        $value = $this->data[$field] ?? null;
        if (!empty($value) && !is_array($value)) {
            $this->errors[] = $message ?: "{$field} 必须是数组";
        }
        return $this;
    }
    
    /**
     * 布尔值验证
     */
    public function boolean(string $field, string $message = ''): self {
        $value = $this->data[$field] ?? null;
        if ($value !== null && !is_bool($value) && !in_array($value, [0, 1, '0', '1', true, false], true)) {
            $this->errors[] = $message ?: "{$field} 必须是布尔值";
        }
        return $this;
    }
    
    /**
     * 枚举值验证
     */
    public function in(string $field, array $allowed, string $message = ''): self {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && !in_array($value, $allowed)) {
            $this->errors[] = $message ?: "{$field} 的值无效";
        }
        return $this;
    }
    
    /**
     * 正则验证
     */
    public function regex(string $field, string $pattern, string $message = ''): self {
        $value = $this->data[$field] ?? '';
        if (!empty($value) && !preg_match($pattern, $value)) {
            $this->errors[] = $message ?: "{$field} 格式不正确";
        }
        return $this;
    }
    
    /**
     * 域名验证
     */
    public function domain(string $field, string $message = ''): self {
        $value = $this->data[$field] ?? '';
        if (!empty($value)) {
            // 简单域名验证
            $pattern = '/^([a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/';
            if (!preg_match($pattern, $value)) {
                $this->errors[] = $message ?: "{$field} 不是有效的域名";
            }
        }
        return $this;
    }
    
    /**
     * 日期验证
     */
    public function date(string $field, string $format = 'Y-m-d', string $message = ''): self {
        $value = $this->data[$field] ?? '';
        if (!empty($value)) {
            $d = DateTime::createFromFormat($format, $value);
            if (!$d || $d->format($format) !== $value) {
                $this->errors[] = $message ?: "{$field} 不是有效的日期格式";
            }
        }
        return $this;
    }
    
    /**
     * 自定义验证
     */
    public function custom(string $field, callable $callback, string $message = ''): self {
        $value = $this->data[$field] ?? null;
        if (!$callback($value, $this->data)) {
            $this->errors[] = $message ?: "{$field} 验证失败";
        }
        return $this;
    }
    
    // ==================== 静态快捷方法 ====================
    
    /**
     * 快速验证 IP
     */
    public static function isValidIp(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    
    /**
     * 快速验证 URL
     */
    public static function isValidUrl(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * 快速验证邮箱
     */
    public static function isValidEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * 验证并返回结果
     */
    public static function validate(array $data, array $rules): array {
        $validator = new self($data);
        
        foreach ($rules as $field => $fieldRules) {
            foreach ($fieldRules as $rule => $params) {
                if (is_numeric($rule)) {
                    $rule = $params;
                    $params = [];
                }
                
                if (!is_array($params)) {
                    $params = [$params];
                }
                
                switch ($rule) {
                    case 'required':
                        $validator->required($field, $params[0] ?? '');
                        break;
                    case 'ip':
                        $validator->ip($field, $params[0] ?? '');
                        break;
                    case 'url':
                        $validator->url($field, $params[0] ?? '');
                        break;
                    case 'email':
                        $validator->email($field, $params[0] ?? '');
                        break;
                    case 'min':
                        $validator->minLength($field, $params[0] ?? 0, $params[1] ?? '');
                        break;
                    case 'max':
                        $validator->maxLength($field, $params[0] ?? 255, $params[1] ?? '');
                        break;
                    case 'in':
                        $validator->in($field, $params[0] ?? [], $params[1] ?? '');
                        break;
                }
            }
        }
        
        return [
            'valid' => $validator->passes(),
            'errors' => $validator->getErrors()
        ];
    }
}
