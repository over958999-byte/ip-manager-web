
import requests
import json
import os
import sys
import time # 用于在添加 Zone 后稍微等待，确保 API 状态同步

# --- Cloudflare API 配置 ---
CLOUDFLARE_API_BASE_URL = "https://api.cloudflare.com/client/v4"

# --- 配置文件名 ---
DOMAINS_FILE = "domains.txt"  # 域名列表文件名

# --- 要为每个域名自动添加的固定 DNS 记录 ---
# 请根据你的实际需求修改以下信息
# record_type: A, CNAME, MX, TXT, AAAA 等
# name: 子域名，如 "www", "mail", "@" (代表根域名)
# content: 记录值，如 IP 地址, CNAME 目标, TXT 文本
# proxied: True (启用 CDN) 或 False (禁用 CDN) - 仅对 A/CNAME 生效
# priority: MX 记录的优先级，整数
DEFAULT_DNS_RECORD = {
    "record_type": "A",
    "name": "www",              # 代表根域名
    "content": "43.135.198.145",   # 示例 IP 地址，请替换为你的实际服务器 IP
    "proxied": True,          # 默认启用 Cloudflare CDN 代理
    "ttl": 3600,              # TTL (Time To Live)，默认 1 小时
    "priority": None          # MX 记录需要优先级，其他类型设为 None
}

# --- 函数：获取 Cloudflare 认证信息 ---
def get_cloudflare_auth():
    """
    从环境变量获取 Cloudflare API Token 和 Account ID。
    """
    api_token = "zCC0Z9y76Gw4OUHnlVah42GnhRIEyhk0kRpO_FDW"
    account_id = "fc83e750bb02ba30cd8a1ee17deeba74"

    if not api_token:
        print("错误: 环境变量 CLOUDFLARE_API_TOKEN 未设置。")
        print("请在运行脚本前设置此环境变量。")
        print("获取方法: 登录 Cloudflare -> 个人资料 -> API Tokens -> 创建一个具有 Zone:Zone/Edit 和 Zone:DNS/Edit 权限的 Token。")
        sys.exit(1)
    if not account_id:
        print("错误: 环境变量 CLOUDFLARE_ACCOUNT_ID 未设置。")
        print("请在运行脚本前设置此环境变量。")
        print("获取方法: 登录 Cloudflare -> 选择账户 -> 任意域名概览页面右侧显示 Account ID。")
        sys.exit(1)

    headers = {
        "Authorization": f"Bearer {api_token}",
        "Content-Type": "application/json"
    }
    return headers, account_id

# --- 函数：向 Cloudflare 添加域名 (Zone) ---
def add_cloudflare_zone(domain_name, headers, account_id):
    """
    向 Cloudflare 账户添加一个新的域名 (Zone)。
    """
    url = f"{CLOUDFLARE_API_BASE_URL}/zones"
    payload = {
        "name": domain_name,
        "account": {
            "id": account_id
        },
        "jump_start": True # 尝试自动导入现有 DNS 记录
    }
    
    print(f"尝试将域名 '{domain_name}' 添加到 Cloudflare...")
    try:
        response = requests.post(url, headers=headers, data=json.dumps(payload))
        response.raise_for_status() # 对 4xx/5xx 响应抛出异常

        data = response.json()
        if data['success']:
            zone_id = data['result']['id']
            name_servers = data['result']['name_servers']
            print(f"成功添加域名 '{domain_name}'。Zone ID: {zone_id}")
            print("------------------------------------------------------------------")
            print(f"重要提示：请务必将你的域名注册商处的 Name Server 更新为以下 Cloudflare 值：")
            for ns in name_servers:
                print(f"- {ns}")
            print("此操作完成后，Cloudflare 才能接管 DNS 管理，解析记录才能生效。")
            print("------------------------------------------------------------------")
            return True, zone_id, name_servers
        else:
            error_details = data.get('errors', [{'message': '未知错误'}])
            print(f"添加域名失败: {error_details}")
            return False, f"添加域名失败: {error_details}", None
    except requests.exceptions.RequestException as e:
        print(f"网络或API错误: {e}")
        if e.response is not None:
            print(f"状态码: {e.response.status_code}, 响应: {e.response.text}")
            try:
                error_data = e.response.json()
                print(f"Cloudflare 错误详情: {error_data}")
            except json.JSONDecodeError:
                pass
        return False, f"网络或API错误: {e}", None
    except json.JSONDecodeError:
        print(f"JSON 解析错误: 响应不是有效的 JSON。响应: {response.text}")
        return False, f"JSON 解析错误", None

# --- 函数：向 Cloudflare Zone 添加 DNS 记录 ---
def add_cloudflare_dns_record(zone_id, headers, record_type, name, content, ttl=3600, proxied=True, priority=None):
    """
    向指定的 Cloudflare Zone 添加一条 DNS 记录。
    """
    url = f"{CLOUDFLARE_API_BASE_URL}/zones/{zone_id}/dns_records"
    payload = {
        "type": record_type.upper(),
        "name": name,
        "content": content,
        "ttl": ttl,
    }

    # 根据记录类型添加额外字段
    if record_type.upper() in ["A", "CNAME"]:
        payload["proxied"] = proxied
    # MX 记录需要优先级
    if record_type.upper() == "MX" and priority is not None:
        payload["priority"] = priority
    # SRV 记录也需要优先级，但通常更复杂，这里仅添加 priority
    elif record_type.upper() == "SRV" and priority is not None:
         payload["priority"] = priority

    print(f"  正在添加 {record_type} 记录 '{name}' -> '{content}'...")
    try:
        response = requests.post(url, headers=headers, data=json.dumps(payload))
        response.raise_for_status()

        data = response.json()
        if data['success']:
            print(f"  成功添加 {record_type} 记录。")
            return True
        else:
            error_details = data.get('errors', [{'message': '未知错误'}])
            print(f"  添加 {record_type} 记录失败: {error_details}")
            return False
    except requests.exceptions.RequestException as e:
        print(f"  网络或API错误: {e}")
        if e.response is not None:
            print(f"  状态码: {e.response.status_code}, 响应: {e.response.text}")
            try:
                error_data = e.response.json()
                print(f"  Cloudflare 错误详情: {error_data}")
            except json.JSONDecodeError:
                pass
        return False
    except json.JSONDecodeError:
        print(f"  JSON 解析错误: 响应不是有效的 JSON。响应: {response.text}")
        return False

# --- 主程序 ---
if __name__ == "__main__":
    # 1. 获取认证信息
    cf_headers, cf_account_id = get_cloudflare_auth()

    # 2. 读取域名列表
    try:
        with open(DOMAINS_FILE, 'r', encoding='utf-8') as f:
            domains = [line.strip().lower() for line in f if line.strip()]
    except FileNotFoundError:
        print(f"错误: 找不到域名列表文件 '{DOMAINS_FILE}'。")
        sys.exit(1)

    if not domains:
        print("错误: 域名列表文件为空。")
        sys.exit(1)

    # 3. 循环处理每个域名
    print("\n--- 开始批量处理域名 ---")
    total_domains_processed = 0
    successful_domains = []

    for domain in domains:
        total_domains_processed += 1
        print(f"\n--- 正在处理域名 ({total_domains_processed}/{len(domains)}): {domain} ---")

        # 添加域名到 Cloudflare
        success_zone, zone_id, name_servers_list = add_cloudflare_zone(domain, cf_headers, cf_account_id)

        if not success_zone:
            print(f"跳过域名 '{domain}' 的 DNS 记录添加，因添加 Zone 失败。")
            continue
        
        # Cloudflare API 可能需要一点时间来同步 Zone 的创建
        print("等待 2 秒，确保 Cloudflare API 状态同步...")
        time.sleep(2)

        # --- 为该域名添加预设的 DNS 记录 ---
        # 替换默认记录中的变量，例如根域名 "@"
        record_name = DEFAULT_DNS_RECORD["name"]
        if record_name == "@":
            record_name = domain # 如果默认是 "@"，替换为实际域名

        # 调用函数添加 DNS 记录
        record_added_successfully = add_cloudflare_dns_record(
            zone_id,
            cf_headers,
            DEFAULT_DNS_RECORD["record_type"],
            record_name,
            DEFAULT_DNS_RECORD["content"],
            ttl=DEFAULT_DNS_RECORD["ttl"],
            proxied=DEFAULT_DNS_RECORD["proxied"],
            priority=DEFAULT_DNS_RECORD["priority"]
        )

        if record_added_successfully:
            print(f"成功为域名 '{domain}' 添加了预设 DNS 记录。")
            successful_domains.append(domain)
        else:
            print(f"为域名 '{domain}' 添加预设 DNS 记录失败。")

    print("\n--- 批量处理完成 ---")
    print(f"总共处理了 {total_domains_processed} 个域名。")
    print(f"成功添加并配置了 {len(successful_domains)} 个域名到 Cloudflare。")
    print("\n请务必将所有成功处理域名的 Name Server 更新为 Cloudflare 提供的值！")

