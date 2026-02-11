<?php

/**
 * OpenAPI/Swagger 文档生成入口
 * 
 * 访问: /api/docs 查看交互式文档
 * 访问: /api/docs/openapi.json 获取 JSON 规范
 */

/**
 * @OA\Info(
 *     title="困King分发平台 API",
 *     version="2.0.0",
 *     description="IP跳转管理系统 RESTful API 文档",
 *     @OA\Contact(
 *         email="admin@example.com",
 *         name="API Support"
 *     ),
 *     @OA\License(
 *         name="MIT",
 *         url="https://opensource.org/licenses/MIT"
 *     )
 * )
 * 
 * @OA\Server(
 *     url="/api/v2",
 *     description="API v2 端点"
 * )
 * 
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="JWT 认证 Token"
 * )
 * 
 * @OA\SecurityScheme(
 *     securityScheme="apiKey",
 *     type="apiKey",
 *     in="header",
 *     name="X-API-Key",
 *     description="API Key 认证"
 * )
 * 
 * @OA\Tag(
 *     name="认证",
 *     description="用户认证相关接口"
 * )
 * @OA\Tag(
 *     name="跳转规则",
 *     description="IP跳转规则管理"
 * )
 * @OA\Tag(
 *     name="短链接",
 *     description="短链接管理"
 * )
 * @OA\Tag(
 *     name="域名",
 *     description="域名管理"
 * )
 * @OA\Tag(
 *     name="Cloudflare",
 *     description="Cloudflare 集成"
 * )
 * @OA\Tag(
 *     name="系统",
 *     description="系统管理接口"
 * )
 */

// 获取请求的路径
$requestPath = $_GET['path'] ?? '';

// 如果请求 openapi.json，生成并返回规范
if ($requestPath === 'openapi.json') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    
    // 扫描控制器目录生成文档
    $openapi = generateOpenApiSpec();
    echo json_encode($openapi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 否则显示 Swagger UI
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API 文档 - 困King分发平台</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <style>
        body { margin: 0; padding: 0; }
        .swagger-ui .topbar { display: none; }
        .swagger-ui .info .title { font-size: 2em; }
        .swagger-ui .scheme-container { background: #fafafa; padding: 15px 0; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        window.onload = () => {
            SwaggerUIBundle({
                url: '/api/docs?path=openapi.json',
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIBundle.SwaggerUIStandalonePreset
                ],
                layout: 'StandaloneLayout',
                persistAuthorization: true,
                defaultModelsExpandDepth: 2,
                defaultModelExpandDepth: 2,
                docExpansion: 'list',
                filter: true,
                showExtensions: true,
                showCommonExtensions: true,
                syntaxHighlight: {
                    activate: true,
                    theme: 'monokai'
                }
            });
        };
    </script>
</body>
</html>
<?php

/**
 * 生成 OpenAPI 规范
 */
function generateOpenApiSpec(): array {
    return [
        'openapi' => '3.0.3',
        'info' => [
            'title' => '困King分发平台 API',
            'version' => '2.0.0',
            'description' => '高性能 IP 跳转管理系统 RESTful API',
            'contact' => [
                'name' => 'API Support',
                'email' => 'admin@example.com'
            ]
        ],
        'servers' => [
            ['url' => '/api/v2', 'description' => 'API v2 端点']
        ],
        'tags' => [
            ['name' => '认证', 'description' => '用户认证相关接口'],
            ['name' => '跳转规则', 'description' => 'IP跳转规则管理'],
            ['name' => '短链接', 'description' => '短链接管理'],
            ['name' => '域名', 'description' => '域名管理'],
            ['name' => 'Cloudflare', 'description' => 'Cloudflare 集成'],
            ['name' => 'IP池', 'description' => 'IP地址池管理'],
            ['name' => '反爬虫', 'description' => '反爬虫配置'],
            ['name' => '系统', 'description' => '系统管理接口'],
        ],
        'paths' => [
            // ==================== 认证 ====================
            '/auth/login' => [
                'post' => [
                    'tags' => ['认证'],
                    'summary' => '用户登录',
                    'operationId' => 'login',
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['username', 'password'],
                                    'properties' => [
                                        'username' => ['type' => 'string', 'example' => 'admin'],
                                        'password' => ['type' => 'string', 'format' => 'password'],
                                        'totp_code' => ['type' => 'string', 'description' => '2FA验证码']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => [
                            'description' => '登录成功',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/LoginResponse']
                                ]
                            ]
                        ],
                        '401' => ['description' => '认证失败'],
                        '429' => ['description' => '请求过于频繁']
                    ]
                ]
            ],
            '/auth/logout' => [
                'post' => [
                    'tags' => ['认证'],
                    'summary' => '用户登出',
                    'operationId' => 'logout',
                    'security' => [['bearerAuth' => []]],
                    'responses' => [
                        '200' => ['description' => '登出成功']
                    ]
                ]
            ],
            '/auth/check' => [
                'get' => [
                    'tags' => ['认证'],
                    'summary' => '检查登录状态',
                    'operationId' => 'checkAuth',
                    'security' => [['bearerAuth' => []]],
                    'responses' => [
                        '200' => ['description' => '已登录'],
                        '401' => ['description' => '未登录']
                    ]
                ]
            ],
            
            // ==================== 跳转规则 ====================
            '/jump-rules' => [
                'get' => [
                    'tags' => ['跳转规则'],
                    'summary' => '获取跳转规则列表',
                    'operationId' => 'listJumpRules',
                    'security' => [['bearerAuth' => []]],
                    'parameters' => [
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
                        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 20]],
                        ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'rule_type', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['ip', 'ip_segment', 'wildcard']]],
                        ['name' => 'enabled', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                        ['name' => 'group_tag', 'in' => 'query', 'schema' => ['type' => 'string']]
                    ],
                    'responses' => [
                        '200' => [
                            'description' => '规则列表',
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/JumpRuleList']
                                ]
                            ]
                        ]
                    ]
                ],
                'post' => [
                    'tags' => ['跳转规则'],
                    'summary' => '创建跳转规则',
                    'operationId' => 'createJumpRule',
                    'security' => [['bearerAuth' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/JumpRuleInput']
                            ]
                        ]
                    ],
                    'responses' => [
                        '201' => ['description' => '创建成功'],
                        '400' => ['description' => '参数错误'],
                        '409' => ['description' => '规则已存在']
                    ]
                ]
            ],
            '/jump-rules/{id}' => [
                'get' => [
                    'tags' => ['跳转规则'],
                    'summary' => '获取单个规则',
                    'operationId' => 'getJumpRule',
                    'security' => [['bearerAuth' => []]],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]
                    ],
                    'responses' => [
                        '200' => ['description' => '规则详情'],
                        '404' => ['description' => '规则不存在']
                    ]
                ],
                'put' => [
                    'tags' => ['跳转规则'],
                    'summary' => '更新跳转规则',
                    'operationId' => 'updateJumpRule',
                    'security' => [['bearerAuth' => []]],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/JumpRuleInput']
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => '更新成功'],
                        '404' => ['description' => '规则不存在']
                    ]
                ],
                'delete' => [
                    'tags' => ['跳转规则'],
                    'summary' => '删除跳转规则',
                    'operationId' => 'deleteJumpRule',
                    'security' => [['bearerAuth' => []]],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]
                    ],
                    'responses' => [
                        '200' => ['description' => '删除成功'],
                        '404' => ['description' => '规则不存在']
                    ]
                ]
            ],
            
            // ==================== 短链接 ====================
            '/shortlinks' => [
                'get' => [
                    'tags' => ['短链接'],
                    'summary' => '获取短链接列表',
                    'operationId' => 'listShortlinks',
                    'security' => [['bearerAuth' => []]],
                    'parameters' => [
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']]
                    ],
                    'responses' => [
                        '200' => ['description' => '短链接列表']
                    ]
                ],
                'post' => [
                    'tags' => ['短链接'],
                    'summary' => '创建短链接',
                    'operationId' => 'createShortlink',
                    'security' => [['bearerAuth' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ShortlinkInput']
                            ]
                        ]
                    ],
                    'responses' => [
                        '201' => ['description' => '创建成功']
                    ]
                ]
            ],
            
            // ==================== 域名 ====================
            '/domains' => [
                'get' => [
                    'tags' => ['域名'],
                    'summary' => '获取域名列表',
                    'operationId' => 'listDomains',
                    'security' => [['bearerAuth' => []]],
                    'responses' => [
                        '200' => ['description' => '域名列表']
                    ]
                ],
                'post' => [
                    'tags' => ['域名'],
                    'summary' => '添加域名',
                    'operationId' => 'createDomain',
                    'security' => [['bearerAuth' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/DomainInput']
                            ]
                        ]
                    ],
                    'responses' => [
                        '201' => ['description' => '添加成功']
                    ]
                ]
            ],
            '/domains/{id}/safety-check' => [
                'post' => [
                    'tags' => ['域名'],
                    'summary' => '域名安全检测',
                    'operationId' => 'checkDomainSafety',
                    'security' => [['bearerAuth' => []]],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]
                    ],
                    'responses' => [
                        '200' => ['description' => '检测结果']
                    ]
                ]
            ],
            
            // ==================== 系统 ====================
            '/system/info' => [
                'get' => [
                    'tags' => ['系统'],
                    'summary' => '获取系统信息',
                    'operationId' => 'getSystemInfo',
                    'security' => [['bearerAuth' => []]],
                    'responses' => [
                        '200' => ['description' => '系统信息']
                    ]
                ]
            ],
            '/system/stats' => [
                'get' => [
                    'tags' => ['系统'],
                    'summary' => '获取统计数据',
                    'operationId' => 'getStats',
                    'security' => [['bearerAuth' => []]],
                    'parameters' => [
                        ['name' => 'range', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['today', '7d', '30d']]]
                    ],
                    'responses' => [
                        '200' => ['description' => '统计数据']
                    ]
                ]
            ],
            '/system/backup' => [
                'post' => [
                    'tags' => ['系统'],
                    'summary' => '创建备份',
                    'operationId' => 'createBackup',
                    'security' => [['bearerAuth' => []]],
                    'responses' => [
                        '200' => ['description' => '备份创建成功']
                    ]
                ]
            ]
        ],
        'components' => [
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT'
                ],
                'apiKey' => [
                    'type' => 'apiKey',
                    'in' => 'header',
                    'name' => 'X-API-Key'
                ]
            ],
            'schemas' => [
                'LoginResponse' => [
                    'type' => 'object',
                    'properties' => [
                        'success' => ['type' => 'boolean'],
                        'data' => [
                            'type' => 'object',
                            'properties' => [
                                'token' => ['type' => 'string'],
                                'user' => ['$ref' => '#/components/schemas/User'],
                                'expires_at' => ['type' => 'string', 'format' => 'date-time']
                            ]
                        ]
                    ]
                ],
                'User' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'username' => ['type' => 'string'],
                        'role' => ['type' => 'string', 'enum' => ['admin', 'operator', 'viewer']],
                        'totp_enabled' => ['type' => 'boolean']
                    ]
                ],
                'JumpRule' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'rule_type' => ['type' => 'string', 'enum' => ['ip', 'ip_segment', 'wildcard']],
                        'match_key' => ['type' => 'string'],
                        'target_url' => ['type' => 'string', 'format' => 'uri'],
                        'enabled' => ['type' => 'boolean'],
                        'group_tag' => ['type' => 'string'],
                        'priority' => ['type' => 'integer'],
                        'hit_count' => ['type' => 'integer'],
                        'created_at' => ['type' => 'string', 'format' => 'date-time'],
                        'updated_at' => ['type' => 'string', 'format' => 'date-time']
                    ]
                ],
                'JumpRuleInput' => [
                    'type' => 'object',
                    'required' => ['rule_type', 'match_key', 'target_url'],
                    'properties' => [
                        'rule_type' => ['type' => 'string', 'enum' => ['ip', 'ip_segment', 'wildcard']],
                        'match_key' => ['type' => 'string', 'description' => 'IP地址、IP段或通配符'],
                        'target_url' => ['type' => 'string', 'format' => 'uri'],
                        'enabled' => ['type' => 'boolean', 'default' => true],
                        'group_tag' => ['type' => 'string'],
                        'priority' => ['type' => 'integer', 'default' => 0],
                        'remark' => ['type' => 'string']
                    ]
                ],
                'JumpRuleList' => [
                    'type' => 'object',
                    'properties' => [
                        'success' => ['type' => 'boolean'],
                        'data' => [
                            'type' => 'object',
                            'properties' => [
                                'items' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => '#/components/schemas/JumpRule']
                                ],
                                'total' => ['type' => 'integer'],
                                'page' => ['type' => 'integer'],
                                'per_page' => ['type' => 'integer']
                            ]
                        ]
                    ]
                ],
                'ShortlinkInput' => [
                    'type' => 'object',
                    'required' => ['target_url'],
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => '自定义短码，留空自动生成'],
                        'target_url' => ['type' => 'string', 'format' => 'uri'],
                        'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                        'max_clicks' => ['type' => 'integer'],
                        'password' => ['type' => 'string']
                    ]
                ],
                'DomainInput' => [
                    'type' => 'object',
                    'required' => ['domain'],
                    'properties' => [
                        'domain' => ['type' => 'string', 'format' => 'hostname'],
                        'type' => ['type' => 'string', 'enum' => ['jump', 'shortlink', 'both']],
                        'ssl_enabled' => ['type' => 'boolean'],
                        'cloudflare_zone_id' => ['type' => 'string']
                    ]
                ],
                'ApiResponse' => [
                    'type' => 'object',
                    'properties' => [
                        'success' => ['type' => 'boolean'],
                        'data' => ['type' => 'object'],
                        'message' => ['type' => 'string'],
                        'error' => ['type' => 'string']
                    ]
                ],
                'PaginationMeta' => [
                    'type' => 'object',
                    'properties' => [
                        'total' => ['type' => 'integer'],
                        'page' => ['type' => 'integer'],
                        'per_page' => ['type' => 'integer'],
                        'total_pages' => ['type' => 'integer']
                    ]
                ],
                'Error' => [
                    'type' => 'object',
                    'properties' => [
                        'success' => ['type' => 'boolean', 'example' => false],
                        'error' => ['type' => 'string'],
                        'code' => ['type' => 'integer']
                    ]
                ]
            ]
        ]
    ];
}
