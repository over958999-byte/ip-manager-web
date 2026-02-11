<?php
/**
 * 系统控制器
 * 处理系统信息、更新检查、备份等功能
 */

require_once __DIR__ . '/BaseController.php';

class SystemController extends BaseController
{
    private const REPO_URL = 'https://api.github.com/repos/over958999-byte/ip-manager-web/commits/master';
    private const CURRENT_VERSION = '1.0.0';
    
    /**
     * 获取系统信息
     * GET /api/v2/system/info
     */
    public function info(): void
    {
        $installDir = realpath(__DIR__ . '/../../..');
        $localVersionFile = $installDir . '/.git/refs/heads/master';
        
        $localVersion = '';
        if (file_exists($localVersionFile)) {
            $localVersion = substr(trim(file_get_contents($localVersionFile)), 0, 7);
        }
        
        $this->success([
            'version' => self::CURRENT_VERSION,
            'commit' => $localVersion,
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'install_dir' => $installDir,
            'is_git_repo' => is_dir($installDir . '/.git')
        ]);
    }
    
    /**
     * 检查系统更新
     * GET /api/v2/system/check-update
     */
    public function checkUpdate(): void
    {
        $installDir = realpath(__DIR__ . '/../../..');
        $localVersionFile = $installDir . '/.git/refs/heads/master';
        
        $localVersion = '';
        if (file_exists($localVersionFile)) {
            $localVersion = trim(file_get_contents($localVersionFile));
        }
        
        // 获取远程最新版本
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::REPO_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'IP-Manager-Updater',
            CURLOPT_TIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            $this->error('无法连接到GitHub，请检查网络');
        }
        
        $data = json_decode($response, true);
        $remoteVersion = $data['sha'] ?? '';
        $commitMessage = $data['commit']['message'] ?? '';
        $commitDate = $data['commit']['committer']['date'] ?? '';
        
        $hasUpdate = !empty($remoteVersion) && $remoteVersion !== $localVersion;
        
        $this->success([
            'has_update' => $hasUpdate,
            'local_version' => substr($localVersion, 0, 7),
            'remote_version' => substr($remoteVersion, 0, 7),
            'commit_message' => $commitMessage,
            'commit_date' => $commitDate,
            'current_version' => self::CURRENT_VERSION
        ]);
    }
    
    /**
     * 执行系统更新
     * POST /api/v2/system/update
     */
    public function update(): void
    {
        $this->requireLogin();
        
        $installDir = realpath(__DIR__ . '/../../..');
        
        // 检查是否是 git 仓库
        if (!is_dir($installDir . '/.git')) {
            $this->error('当前不是Git仓库，无法自动更新。请手动更新或重新部署。');
        }
        
        // 备份配置文件
        $configFile = $installDir . '/backend/core/db_config.php';
        $configBackup = '';
        if (file_exists($configFile)) {
            $configBackup = file_get_contents($configFile);
        }
        
        // 切换到项目目录
        chdir($installDir);
        
        // 获取更新
        $output = [];
        exec('git fetch origin 2>&1', $output, $returnVar);
        if ($returnVar !== 0) {
            $this->error('Git fetch失败: ' . implode("\n", $output));
        }
        
        // 重置到最新版本
        $output = [];
        exec('git reset --hard origin/master 2>&1', $output, $returnVar);
        if ($returnVar !== 0) {
            $this->error('Git reset失败: ' . implode("\n", $output));
        }
        
        // 恢复配置文件
        if (!empty($configBackup)) {
            file_put_contents($configFile, $configBackup);
        }
        
        // 自动构建前端
        $buildResult = $this->buildFrontend($installDir);
        
        $this->audit('system_update', 'system');
        
        $this->success([
            'build_success' => $buildResult['success'],
            'build_output' => $buildResult['output'],
            'git_output' => implode("\n", $output)
        ], '更新成功！' . ($buildResult['success'] ? '前端已自动重新编译。' : ''));
    }
    
    /**
     * 构建前端
     */
    private function buildFrontend(string $installDir): array
    {
        $buildOutput = [];
        $buildSuccess = false;
        $frontendDir = $installDir . '/backend/frontend';
        
        if (!is_dir($frontendDir) || !file_exists($frontendDir . '/package.json')) {
            return ['success' => false, 'output' => '前端目录不存在'];
        }
        
        chdir($frontendDir);
        
        // 检查 node 和 npm 是否可用
        $nodeVersion = shell_exec('node -v 2>&1');
        $npmVersion = shell_exec('npm -v 2>&1');
        
        if (!$nodeVersion || !$npmVersion || strpos($nodeVersion, 'v') !== 0) {
            $buildOutput[] = '未检测到 Node.js 环境，跳过前端编译';
            $buildOutput[] = '请手动执行: cd backend/frontend && npm install && npm run build';
            return ['success' => false, 'output' => implode("\n", $buildOutput)];
        }
        
        // 检查 node_modules 是否存在
        if (!is_dir($frontendDir . '/node_modules')) {
            $buildOutput[] = '正在安装依赖...';
            exec('npm install 2>&1', $npmInstallOutput, $npmInstallReturn);
            $buildOutput = array_merge($buildOutput, $npmInstallOutput);
            
            if ($npmInstallReturn !== 0) {
                $buildOutput[] = '依赖安装失败，跳过编译';
                return ['success' => false, 'output' => implode("\n", $buildOutput)];
            }
        }
        
        // 执行编译
        $buildOutput[] = '正在编译前端...';
        exec('npm run build 2>&1', $npmBuildOutput, $npmBuildReturn);
        $buildOutput = array_merge($buildOutput, $npmBuildOutput);
        
        if ($npmBuildReturn === 0) {
            $buildSuccess = true;
            $buildOutput[] = '前端编译成功！';
        } else {
            $buildOutput[] = '前端编译失败';
        }
        
        return ['success' => $buildSuccess, 'output' => implode("\n", $buildOutput)];
    }
    
    /**
     * 获取访问统计
     * GET /api/v2/system/stats
     */
    public function getStats(): void
    {
        $this->requireLogin();
        
        $stats = $this->db->getVisitStats();
        $this->success($stats);
    }
    
    /**
     * 获取指定 IP 的统计
     * GET /api/v2/system/stats/{ip}
     */
    public function getIpStats(string $ip): void
    {
        $this->requireLogin();
        
        if (empty($ip)) {
            $this->error('IP不能为空');
        }
        
        $stats = $this->db->getIpStats($ip);
        $this->success($stats);
    }
    
    /**
     * 清空统计
     * DELETE /api/v2/system/stats
     */
    public function clearStats(): void
    {
        $this->requireLogin();
        
        $ip = trim($this->param('ip', ''));
        $this->db->clearStats($ip ?: null);
        
        $this->audit('clear_stats', 'system', null, $ip ? ['ip' => $ip] : []);
        $this->success(null, '统计已清空');
    }
    
    /**
     * 导出所有重定向规则
     * GET /api/v2/system/export
     */
    public function export(): void
    {
        $this->requireLogin();
        
        $redirects = $this->db->getRedirects();
        $this->success($redirects);
    }
    
    /**
     * 导入重定向规则
     * POST /api/v2/system/import
     */
    public function import(): void
    {
        $this->requireLogin();
        
        $data = $this->param('data', []);
        
        if (empty($data)) {
            $this->error('导入数据为空');
        }
        
        $count = 0;
        foreach ($data as $ip => $info) {
            if (is_array($info) && isset($info['url'])) {
                if ($this->db->addRedirect($ip, $info['url'], $info['note'] ?? '')) {
                    $this->db->updateRedirect($ip, $info);
                    $count++;
                }
            }
        }
        
        $this->audit('import', 'system', null, ['count' => $count]);
        $this->success(['count' => $count], "成功导入 {$count} 条记录");
    }
}
