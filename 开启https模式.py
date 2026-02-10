import requests
import os
import time
import json # 用于美化打印JSON响应或调试

# --- 配置您的Cloudflare API凭证和域名文件路径 ---
# 强烈建议将 API Token 设置为环境变量，而不是直接硬编码在代码中
# export CLOUDFLARE_API_TOKEN="YOUR_CLOUDFLARE_API_TOKEN"

CF_API_TOKEN = "zCC0Z9y76Gw4OUHnlVah42GnhRIEyhk0kRpO_FDW"

# 存储需要开启功能的域名列表的文本文件路径
DOMAIN_FILE_PATH = "domains.txt" 

BASE_URL = "https://api.cloudflare.com/client/v4"

# --- 全局HTTP头，包含认证信息 ---
HEADERS = {
    "Authorization": f"Bearer {CF_API_TOKEN}",
    "Content-Type": "application/json"
}

# --- 辅助函数：发送Cloudflare API请求 ---
def make_cloudflare_api_request(method, path, params=None, data=None):
    """
    发送一个HTTP请求到Cloudflare API。
    :param method: HTTP方法 (GET, PATCH)
    :param path: API路径，例如 "/zones" 或 "/zones/{zone_id}/settings/always_use_https"
    :param params: GET请求的查询参数 (字典)
    :param data: PATCH请求的JSON数据 (字典)
    :return: 成功时返回JSON响应的'result'部分，失败时返回None
    """
    url = f"{BASE_URL}{path}"
    try:
        if method == "GET":
            response = requests.get(url, headers=HEADERS, params=params, timeout=10)
        elif method == "PATCH":
            response = requests.patch(url, headers=HEADERS, json=data, timeout=10)
        else:
            print(f"不支持的HTTP方法: {method}")
            return None

        response.raise_for_status() # 对HTTP错误状态码 (4xx 或 5xx) 抛出异常
        response_json = response.json()

        if response_json.get('success'):
            return response_json.get('result')
        else:
            errors = response_json.get('errors', [])
            print(f"Cloudflare API 错误: {path} - {json.dumps(errors, indent=2, ensure_ascii=False)}")
            return None

    except requests.exceptions.HTTPError as e:
        print(f"HTTP 错误: {e.response.status_code} - {e.response.text}")
    except requests.exceptions.ConnectionError as e:
        print(f"连接错误: {e}")
    except requests.exceptions.Timeout as e:
        print(f"请求超时: {e}")
    except requests.exceptions.RequestException as e:
        print(f"请求发生未知错误: {e}")
    except json.JSONDecodeError:
        print(f"无法解析JSON响应: {response.text}")
    return None

# --- 从TXT文件读取域名列表 ---
def read_domains_from_file(file_path):
    """
    从指定的文本文件读取域名列表。每行一个域名。
    :param file_path: 文本文件的路径。
    :return: 包含域名的列表，如果文件不存在或为空，返回空列表。
    """
    domains = []
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            for line in f:
                domain = line.strip()
                if domain: # 确保不是空行
                    domains.append(domain)
    except FileNotFoundError:
        print(f"错误: 域名文件 '{file_path}' 未找到。请确保文件存在。")
    except Exception as e:
        print(f"读取域名文件时发生错误: {e}")
    return domains

# --- 根据域名获取 Zone ID ---
def get_zone_id_by_name(domain_name):
    """
    根据域名获取其在Cloudflare中的Zone ID。
    :param domain_name: 待查询的域名。
    :return: Zone ID (字符串) 如果找到，否则返回None。
    """
    params = {"name": domain_name}
    result = make_cloudflare_api_request("GET", "/zones", params=params)
    
    if result and len(result) > 0:
        # Cloudflare API 返回一个列表，即使只有一个匹配项
        return result[0]['id']
    else:
        print(f"未在Cloudflare账户中找到域名 '{domain_name}' 的Zone ID。")
        return None

# --- 开启一个域名的“始终使用 HTTPS” ---
def enable_always_use_https(zone_id, domain_name):
    """
    为指定Zone开启“始终使用 HTTPS”功能。
    :param zone_id: Zone的ID。
    :param domain_name: Zone的名称 (用于日志输出)。
    :return: True如果操作成功或已启用，False如果失败。
    """
    path = f"/zones/{zone_id}/settings/always_use_https"
    
    print(f"  正在检查域名 '{domain_name}' 的'始终使用 HTTPS'设置...")
    current_setting = make_cloudflare_api_request("GET", path)

    if current_setting:
        if current_setting.get('value') == 'on':
            print(f"  域名 '{domain_name}' 的'始终使用 HTTPS'已处于开启状态。跳过。")
            return True
        else:
            print(f"  域名 '{domain_name}' 的'始终使用 HTTPS'处于关闭状态，正在尝试开启...")
            update_data = {"value": "on"}
            update_result = make_cloudflare_api_request("PATCH", path, data=update_data)
            if update_result and update_result.get('value') == 'on':
                print(f"  成功为域名 '{domain_name}' 开启'始终使用 HTTPS'。")
                return True
            else:
                print(f"  未能为域名 '{domain_name}' 开启'始终使用 HTTPS'。")
                return False
    else:
        print(f"  未能获取域名 '{domain_name}' 的'始终使用 HTTPS'设置，无法操作。")
        return False

# --- 主执行逻辑 ---
if __name__ == "__main__":
    if not CF_API_TOKEN:
        print("错误: 缺少 CLOUDFLARE_API_TOKEN 环境变量。请设置后再运行。")
        exit(1)
    
    # 验证API Token是否有效 (可选，但推荐)
    # 尝试获取用户Token信息，如果成功则Token有效
 

    print(f"\n--- 正在从文件 '{DOMAIN_FILE_PATH}' 读取域名列表 ---")
    domains_to_process = read_domains_from_file(DOMAIN_FILE_PATH)

    if not domains_to_process:
        print("域名文件为空或读取失败。")
        exit(0)

    print(f"成功读取 {len(domains_to_process)} 个域名。")
    print("--- 开始批量处理 ---")

    processed_count = 0
    success_count = 0
    failure_count = 0
    skipped_count = 0

    for domain_name in domains_to_process:
        print(f"\n正在处理域名: {domain_name}")
        processed_count += 1
        
        zone_id = get_zone_id_by_name(domain_name)
        
        if zone_id:
            if enable_always_use_https(zone_id, domain_name):
                success_count += 1
            else:
                failure_count += 1
        else:
            skipped_count += 1
            print(f"  跳过域名 '{domain_name}'，因为它在Cloudflare中未找到或无法获取Zone ID。")
        
        # 在处理每个域名后暂停，以避免速率限制
        time.sleep(2) 
    
    print("\n--- 批量处理完成 ---")
    print(f"总计处理域名: {processed_count}")
    print(f"成功开启/已开启域名: {success_count}")
    print(f"失败域名: {failure_count}")
    print(f"跳过域名 (未找到或无法操作): {skipped_count}")
