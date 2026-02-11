// @ts-nocheck
// 注意：运行前需要安装 Playwright: npm install -D @playwright/test
// 然后运行: npx playwright install
import { test, expect } from '@playwright/test'

/**
 * 登录页面 E2E 测试
 */
test.describe('登录功能', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login')
  })

  test('应该显示登录表单', async ({ page }) => {
    await expect(page.locator('input[type="text"]')).toBeVisible()
    await expect(page.locator('input[type="password"]')).toBeVisible()
    await expect(page.locator('button[type="submit"]')).toBeVisible()
  })

  test('空表单提交应该显示错误', async ({ page }) => {
    await page.click('button[type="submit"]')
    await expect(page.locator('.el-form-item__error')).toBeVisible()
  })

  test('错误密码应该显示错误消息', async ({ page }) => {
    await page.fill('input[type="text"]', 'admin')
    await page.fill('input[type="password"]', 'wrongpassword')
    await page.click('button[type="submit"]')
    
    // 等待错误提示
    await expect(page.locator('.el-message--error')).toBeVisible({ timeout: 5000 })
  })

  test('正确凭证应该跳转到仪表盘', async ({ page }) => {
    await page.fill('input[type="text"]', 'admin')
    await page.fill('input[type="password"]', 'admin123')
    await page.click('button[type="submit"]')
    
    // 等待跳转
    await expect(page).toHaveURL(/.*dashboard/, { timeout: 10000 })
  })
})

/**
 * 仪表盘 E2E 测试
 */
test.describe('仪表盘', () => {
  test.beforeEach(async ({ page }) => {
    // 先登录
    await page.goto('/login')
    await page.fill('input[type="text"]', 'admin')
    await page.fill('input[type="password"]', 'admin123')
    await page.click('button[type="submit"]')
    await page.waitForURL(/.*dashboard/)
  })

  test('应该显示统计卡片', async ({ page }) => {
    await expect(page.locator('.stat-card')).toHaveCount(4)
  })

  test('应该显示图表', async ({ page }) => {
    await expect(page.locator('[class*="echarts"]')).toBeVisible()
  })
})

/**
 * 跳转规则管理 E2E 测试
 */
test.describe('跳转规则管理', () => {
  test.beforeEach(async ({ page }) => {
    // 登录并导航到规则页面
    await page.goto('/login')
    await page.fill('input[type="text"]', 'admin')
    await page.fill('input[type="password"]', 'admin123')
    await page.click('button[type="submit"]')
    await page.waitForURL(/.*dashboard/)
    await page.click('text=跳转规则')
  })

  test('应该显示规则列表', async ({ page }) => {
    await expect(page.locator('.el-table')).toBeVisible()
  })

  test('应该能打开新建规则对话框', async ({ page }) => {
    await page.click('text=新建规则')
    await expect(page.locator('.el-dialog')).toBeVisible()
  })

  test('应该能搜索规则', async ({ page }) => {
    await page.fill('input[placeholder*="搜索"]', '192.168')
    await page.press('input[placeholder*="搜索"]', 'Enter')
    
    // 等待搜索结果
    await page.waitForTimeout(1000)
  })
})

/**
 * 响应式设计测试
 */
test.describe('响应式设计', () => {
  test('移动端应该显示汉堡菜单', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 })
    await page.goto('/login')
    await page.fill('input[type="text"]', 'admin')
    await page.fill('input[type="password"]', 'admin123')
    await page.click('button[type="submit"]')
    await page.waitForURL(/.*dashboard/)
    
    // 检查移动端菜单按钮
    await expect(page.locator('.hamburger, .menu-toggle')).toBeVisible()
  })
})
