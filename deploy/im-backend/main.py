import asyncio
import time
import websockets
import aiomysql
import json
import os
import traceback
import base64
import hashlib
import hmac
from datetime import datetime

def load_env_file(path=".env"):
    if not os.path.exists(path):
        return

    with open(path, "r", encoding="utf-8") as env_file:
        for line in env_file:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue

            key, value = line.split("=", 1)
            os.environ.setdefault(key.strip(), value.strip().strip("\"'"))

load_env_file()

# 客户端连接管理 {用户唯一标识: websocket}
clients = {}

# MySQL 连接池配置
DB_CONFIG = {
    'host': os.getenv('DB_HOST', '127.0.0.1'),
    'port': int(os.getenv('DB_PORT', '3306')),
    'user': os.getenv('DB_USERNAME', 'outer'),
    'password': os.getenv('DB_PASSWORD', ''),
    'db': os.getenv('DB_DATABASE', 'outer')
}
DB_TABLE_PREFIX = os.getenv('DB_TABLE_PREFIX', 'fb_')
CHAT_TABLE = os.getenv('IM_CHAT_TABLE', f'{DB_TABLE_PREFIX}chat')
IM_AUTH_SECRET = os.getenv('IM_AUTH_SECRET', '')
MAX_TEXT_MESSAGE_LENGTH = int(os.getenv('IM_MAX_TEXT_MESSAGE_LENGTH', '2000'))
MAX_IMAGE_MESSAGE_LENGTH = int(os.getenv('IM_MAX_IMAGE_MESSAGE_LENGTH', '2048'))
MAX_MEDIA_MESSAGE_LENGTH = int(os.getenv('IM_MAX_MEDIA_MESSAGE_LENGTH', '4096'))

def quote_identifier(name):
    if not name.replace('_', '').isalnum():
        raise ValueError(f"Invalid SQL identifier: {name}")
    return f"`{name}`"

CHAT_TABLE_SQL = quote_identifier(CHAT_TABLE)

async def create_db_pool():
    """创建MySQL连接池（自动提交+读已提交隔离级别）"""
    return await aiomysql.create_pool(
        host=DB_CONFIG['host'],
        port=DB_CONFIG['port'],
        user=DB_CONFIG['user'],
        password=DB_CONFIG['password'],
        db=DB_CONFIG['db'],
        minsize=1,
        maxsize=10,
        autocommit=True,
        init_command="SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED"
    )

# 数据库连接池
db_pool = None
CHAT_CONTEXT_COLUMNS = set()
CHAT_TIME_DATA_TYPE = ''

async def validate_chat_table():
    """Fail fast when the configured chat table is missing."""
    global CHAT_CONTEXT_COLUMNS, CHAT_TIME_DATA_TYPE
    async with db_pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                """
                SELECT COUNT(*)
                FROM information_schema.tables
                WHERE table_schema = %s AND table_name = %s
                """,
                (DB_CONFIG['db'], CHAT_TABLE)
            )
            count = (await cur.fetchone())[0]
            if count == 0:
                raise RuntimeError(f"Chat table `{CHAT_TABLE}` not found in database `{DB_CONFIG['db']}`")

            await cur.execute(
                """
                SELECT column_name, data_type
                FROM information_schema.columns
                WHERE table_schema = %s AND table_name = %s
                """,
                (DB_CONFIG['db'], CHAT_TABLE)
            )
            column_types = {row[0]: row[1] for row in await cur.fetchall()}
            CHAT_CONTEXT_COLUMNS = set(column_types.keys())
            CHAT_TIME_DATA_TYPE = column_types.get('time', '')

def normalize_positive_int(value):
    try:
        value = int(value)
    except (TypeError, ValueError):
        return 0
    return value if value > 0 else 0

def base64url_decode(value):
    padding = '=' * (-len(value) % 4)
    return base64.urlsafe_b64decode((value + padding).encode('utf-8'))

def validate_auth_token(token, user_type, user_id):
    if not IM_AUTH_SECRET:
        return {}

    if not token or '.' not in token:
        raise ValueError("Missing auth token")

    encoded_payload, signature = token.rsplit('.', 1)
    expected = hmac.new(IM_AUTH_SECRET.encode('utf-8'), encoded_payload.encode('utf-8'), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        raise ValueError("Invalid auth token signature")

    try:
        payload = json.loads(base64url_decode(encoded_payload).decode('utf-8'))
    except Exception as exc:
        raise ValueError("Invalid auth token payload") from exc

    if payload.get('type') != user_type:
        raise ValueError("Invalid auth token type")

    if str(payload.get('user_id')) != str(user_id):
        raise ValueError("Invalid auth token user")

    if normalize_positive_int(payload.get('exp')) < int(time.time()):
        raise ValueError("Expired auth token")

    return payload

def assert_token_int_scope(auth_payload, key, actual, error_message):
    allowed = normalize_positive_int(auth_payload.get(key, 0))
    if allowed and normalize_positive_int(actual) != allowed:
        raise ValueError(error_message)

def assert_user_chat_scope(auth_payload, uid=None, product_id=None, store_id=None):
    if uid is not None:
        assert_token_int_scope(auth_payload, "uid", uid, "Unauthorized target_uid")
    if product_id is not None:
        assert_token_int_scope(auth_payload, "product_id", product_id, "Unauthorized product_id")
    if store_id is not None:
        assert_token_int_scope(auth_payload, "store_id", store_id, "Unauthorized store_id")

def validate_chat_payload(content, msg_type):
    if isinstance(msg_type, bool):
        raise ValueError("Invalid msg_type")
    try:
        normalized_type = int(msg_type)
    except (TypeError, ValueError):
        raise ValueError("Invalid msg_type")

    if normalized_type not in (1, 2, 3, 4, 5):
        raise ValueError("Invalid msg_type")

    if not isinstance(content, str):
        raise ValueError("Invalid message content")

    normalized_content = content.strip()
    if normalized_type == 1:
        if not normalized_content:
            raise ValueError("Empty text message")
        if len(normalized_content) > MAX_TEXT_MESSAGE_LENGTH:
            raise ValueError("Text message too long")
        return normalized_content, normalized_type

    if not normalized_content:
        raise ValueError("Empty image message")
    if normalized_type == 2:
        if len(normalized_content) > MAX_IMAGE_MESSAGE_LENGTH:
            raise ValueError("Image message too long")
        if not normalized_content.startswith('/attachment/'):
            raise ValueError("Invalid image message URL")
        if '\\' in normalized_content or '..' in normalized_content or any(ord(ch) < 32 for ch in normalized_content):
            raise ValueError("Invalid image message URL")
        return normalized_content, normalized_type

    if len(normalized_content) > MAX_MEDIA_MESSAGE_LENGTH:
        raise ValueError("Media message too long")
    if not normalized_content.startswith('/mall/chat/media-view?'):
        raise ValueError("Invalid media message URL")
    if '\\' in normalized_content or '..' in normalized_content or any(ord(ch) < 32 for ch in normalized_content):
        raise ValueError("Invalid media message URL")
    if 'media_id=' not in normalized_content or 'token=' not in normalized_content:
        raise ValueError("Invalid media message URL")

    return normalized_content, normalized_type

def has_chat_context_columns():
    return {'product_id', 'store_id'}.issubset(CHAT_CONTEXT_COLUMNS)

def has_chat_read_columns():
    return {'user_read_at', 'merchant_read_at'}.issubset(CHAT_CONTEXT_COLUMNS)

def chat_time_uses_unix_timestamp():
    return CHAT_TIME_DATA_TYPE in {'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'}

def chat_context_select(prefix=''):
    if has_chat_context_columns():
        return f", {prefix}product_id, {prefix}store_id"
    return ", 0 AS product_id, 0 AS store_id"

def chat_read_select(prefix=''):
    if has_chat_read_columns():
        return f", {prefix}user_read_at, {prefix}merchant_read_at"
    return ", 0 AS user_read_at, 0 AS merchant_read_at"

def has_chat_translation_columns():
    return {
        'original_content',
        'source_language',
        'target_language',
        'translated_content',
        'translation_status',
        'translation_provider',
        'translation_error',
        'translated_at',
    }.issubset(CHAT_CONTEXT_COLUMNS)

def chat_translation_select(prefix=''):
    if has_chat_translation_columns():
        return (
            f", {prefix}original_content, {prefix}source_language, {prefix}target_language"
            f", {prefix}translated_content, {prefix}translation_status, {prefix}translation_provider"
            f", {prefix}translation_error, {prefix}translated_at"
        )
    return (
        ", '' AS original_content, '' AS source_language, '' AS target_language"
        ", '' AS translated_content, 'none' AS translation_status, '' AS translation_provider"
        ", '' AS translation_error, 0 AS translated_at"
    )

def normalize_language(value):
    value = str(value or '').strip().replace('_', '-').lower()
    if value.startswith('zh'):
        return 'zh-CN'
    if value.startswith('mn'):
        return 'mn'
    if value.startswith('en'):
        return 'en'
    return ''

def clean_short_text(value, max_length):
    value = str(value or '').strip()
    value = ''.join(ch for ch in value if ord(ch) >= 32 or ch in '\r\n\t')
    return value[:max_length]

def validate_translation_metadata(msg_data, content, msg_type):
    metadata = {
        'original_content': '',
        'source_language': '',
        'target_language': '',
        'translated_content': '',
        'translation_status': 'none',
        'translation_provider': '',
        'translation_error': '',
        'translated_at': 0,
    }

    if msg_type != 1:
        return metadata

    status = str(msg_data.get('translation_status', 'none') or 'none').strip().lower()
    if status not in ('none', 'translated', 'failed', 'skipped'):
        status = 'none'

    original_content = clean_short_text(msg_data.get('original_content') or content, MAX_TEXT_MESSAGE_LENGTH)
    translated_content = clean_short_text(msg_data.get('translated_content'), MAX_TEXT_MESSAGE_LENGTH)
    source_language = normalize_language(msg_data.get('source_language'))
    target_language = normalize_language(msg_data.get('target_language'))
    provider = clean_short_text(msg_data.get('translation_provider'), 32)
    error = clean_short_text(msg_data.get('translation_error'), 255)

    if status == 'translated' and not translated_content:
        status = 'failed'
        error = error or 'Translated content is empty'
    if status in ('failed', 'skipped') and not translated_content:
        translated_content = ''

    translated_at = 0
    if status in ('translated', 'failed'):
        translated_at = normalize_positive_int(msg_data.get('translated_at')) or int(time.time())

    metadata.update({
        'original_content': original_content,
        'source_language': source_language,
        'target_language': target_language,
        'translated_content': translated_content,
        'translation_status': status,
        'translation_provider': provider,
        'translation_error': error,
        'translated_at': translated_at,
    })
    return metadata

def media_preview_label(msg_type):
    if msg_type == 2:
        return '[图片]'
    if msg_type == 3:
        return '[文件]'
    if msg_type == 4:
        return '[视频]'
    if msg_type == 5:
        return '[语音]'
    return ''

def unread_count_select(role):
    if not has_chat_read_columns():
        return ", 0 AS unread_count"

    if role == "merchant":
        return f""",
                (
                    SELECT COUNT(*)
                    FROM {CHAT_TABLE_SQL} unread
                    WHERE unread.uid = c1.uid
                      AND unread.uuid = c1.uuid
                      AND unread.`from` = 1
                      AND unread.merchant_read_at = 0
                ) AS unread_count"""

    return f""",
                (
                    SELECT COUNT(*)
                    FROM {CHAT_TABLE_SQL} unread
                    WHERE unread.uuid = c1.uuid
                      AND unread.uid = c1.uid
                      AND unread.`from` = 2
                      AND unread.user_read_at = 0
                ) AS unread_count"""

async def save_message(from_type, uid, content, msg_type, uuid, product_id=0, store_id=0, translation_metadata=None):
    """保存消息到数据库
    
    Args:
        from_type: 发送方类型，1为用户，2为商家
        uid: 商家id
        content: 消息内容
        msg_type: 消息类型，1文字，2图片，3文件，4视频，5语音
        uuid: 用户unique_id
    
    Returns:
        message_id: 消息ID
    """
    async with db_pool.acquire() as conn:
        async with conn.cursor() as cur:
            columns = ['`from`', 'uid']
            values = [from_type, uid]

            if has_chat_context_columns():
                columns.extend(['product_id', 'store_id'])
                values.extend([product_id, store_id])

            columns.extend(['content', '`type`', 'time', 'uuid'])
            if chat_time_uses_unix_timestamp():
                values.extend([content, msg_type, int(time.time()), uuid])
            else:
                values.extend([content, msg_type, uuid])

            if has_chat_read_columns():
                now_ts = int(time.time())
                columns.extend(['user_read_at', 'merchant_read_at'])
                if from_type == 1:
                    values.extend([now_ts, 0])
                else:
                    values.extend([0, now_ts])

            if has_chat_translation_columns():
                translation_metadata = translation_metadata or {}
                columns.extend([
                    'original_content',
                    'source_language',
                    'target_language',
                    'translated_content',
                    'translation_status',
                    'translation_provider',
                    'translation_error',
                    'translated_at',
                ])
                values.extend([
                    translation_metadata.get('original_content', ''),
                    translation_metadata.get('source_language', ''),
                    translation_metadata.get('target_language', ''),
                    translation_metadata.get('translated_content', ''),
                    translation_metadata.get('translation_status', 'none'),
                    translation_metadata.get('translation_provider', ''),
                    translation_metadata.get('translation_error', ''),
                    normalize_positive_int(translation_metadata.get('translated_at')),
                ])

            placeholders = ['%s'] * len(columns)
            if not chat_time_uses_unix_timestamp():
                placeholders[columns.index('time')] = 'NOW()'
            await cur.execute(
                f"INSERT INTO {CHAT_TABLE_SQL} ({', '.join(columns)}) VALUES ({', '.join(placeholders)})",
                values
            )
            message_id = cur.lastrowid
            return message_id

async def mark_chat_read(user_type, uuid, uid):
    if not has_chat_read_columns():
        return 0

    async with db_pool.acquire() as conn:
        async with conn.cursor() as cur:
            now_ts = int(time.time())
            if user_type == "merchant" or user_type == "platform":
                await cur.execute(f"""
                    UPDATE {CHAT_TABLE_SQL}
                    SET merchant_read_at = %s
                    WHERE uuid = %s AND uid = %s AND `from` = 1 AND merchant_read_at = 0
                """, (now_ts, uuid, uid))
            else:
                await cur.execute(f"""
                    UPDATE {CHAT_TABLE_SQL}
                    SET user_read_at = %s
                    WHERE uuid = %s AND uid = %s AND `from` = 2 AND user_read_at = 0
                """, (now_ts, uuid, uid))

            return cur.rowcount

async def get_latest_chat_context(uuid, uid):
    if not has_chat_context_columns():
        return 0, 0

    async with db_pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(f"""
                SELECT product_id, store_id
                FROM {CHAT_TABLE_SQL}
                WHERE uuid = %s AND uid = %s AND (product_id > 0 OR store_id > 0)
                ORDER BY id DESC
                LIMIT 1
            """, (uuid, uid))
            row = await cur.fetchone()
            if not row:
                return 0, 0

            return normalize_positive_int(row.get('product_id')), normalize_positive_int(row.get('store_id'))

async def get_chat_history(uuid, uid, limit=50):
    """获取两个用户之间的聊天历史
    
    Args:
        uuid: 用户unique_id
        uid: 商家id
        limit: 返回消息数量限制
    
    Returns:
        消息列表
    """
    async with db_pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(f"""
                SELECT id, `from`, uid, content, `type` as msg_type, time, uuid {chat_context_select()} {chat_read_select()} {chat_translation_select()}
                FROM {CHAT_TABLE_SQL}
                WHERE uuid = %s AND uid = %s
                ORDER BY id DESC
                LIMIT %s
            """, (uuid, uid, limit))
            messages = await cur.fetchall()
            
            for msg in messages:
                if isinstance(msg['time'], datetime):
                    msg['time'] = msg['time'].strftime('%Y-%m-%d %H:%M:%S')
            
            return list(reversed(messages))

async def get_user_chat_list(uuid):
    """获取用户的聊天列表（最近联系的商家列表）
    
    Args:
        uuid: 用户unique_id
    
    Returns:
        聊天列表
    """
    async with db_pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(f"""
                SELECT c1.uid, c1.content, c1.`type` as msg_type, c1.time, c1.`from` {chat_context_select('c1.')} {chat_read_select('c1.')} {unread_count_select('user')}
                FROM {CHAT_TABLE_SQL} c1
                INNER JOIN (
                    SELECT uid, MAX(id) as max_id
                    FROM {CHAT_TABLE_SQL}
                    WHERE uuid = %s
                    GROUP BY uid
                ) latest ON c1.id = latest.max_id
                WHERE c1.uuid = %s
                ORDER BY c1.time DESC
            """, (uuid, uuid))
            
            chat_list = await cur.fetchall()
            
            for chat in chat_list:
                if isinstance(chat['time'], datetime):
                    chat['time'] = chat['time'].strftime('%Y-%m-%d %H:%M:%S')
                
                # 消息类型显示处理
                label = media_preview_label(chat['msg_type'])
                if label:
                    chat['content'] = label
            
            return chat_list

async def get_merchant_chat_list(uid):
    """获取商家的聊天列表（最近联系的用户列表）
    
    Args:
        uid: 商家id
    
    Returns:
        聊天列表
    """
    async with db_pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(f"""
                SELECT c1.uuid, c1.content, c1.`type` as msg_type, c1.time, c1.`from` {chat_context_select('c1.')} {chat_read_select('c1.')} {unread_count_select('merchant')}
                FROM {CHAT_TABLE_SQL} c1
                INNER JOIN (
                    SELECT uuid, MAX(id) as max_id
                    FROM {CHAT_TABLE_SQL}
                    WHERE uid = %s
                    GROUP BY uuid
                ) latest ON c1.id = latest.max_id
                WHERE c1.uid = %s
                ORDER BY c1.time DESC
            """, (uid, uid))
            
            chat_list = await cur.fetchall()
            
            for chat in chat_list:
                if isinstance(chat['time'], datetime):
                    chat['time'] = chat['time'].strftime('%Y-%m-%d %H:%M:%S')
                
                label = media_preview_label(chat['msg_type'])
                if label:
                    chat['content'] = label
            
            return chat_list

async def get_platform_chat_list():
    """获取平台客服视角的全部商家会话列表。"""
    async with db_pool.acquire() as conn:
        async with conn.cursor(aiomysql.DictCursor) as cur:
            await cur.execute(f"""
                SELECT c1.uid, c1.uuid, c1.content, c1.`type` as msg_type, c1.time, c1.`from` {chat_context_select('c1.')} {chat_read_select('c1.')} {unread_count_select('merchant')}
                FROM {CHAT_TABLE_SQL} c1
                INNER JOIN (
                    SELECT uid, uuid, MAX(id) as max_id
                    FROM {CHAT_TABLE_SQL}
                    GROUP BY uid, uuid
                ) latest ON c1.id = latest.max_id
                ORDER BY c1.time DESC
            """)

            chat_list = await cur.fetchall()

            for chat in chat_list:
                if isinstance(chat['time'], datetime):
                    chat['time'] = chat['time'].strftime('%Y-%m-%d %H:%M:%S')

                label = media_preview_label(chat['msg_type'])
                if label:
                    chat['content'] = label

            return chat_list

async def send_to_user(target_uuid, message):
    """发送消息给指定用户
    
    Args:
        target_uuid: 目标用户的unique_id
        message: 消息内容(JSON字符串)
    """
    if target_uuid in clients:
        try:
            await clients[target_uuid].send(message)
            return True
        except websockets.ConnectionClosed:
            del clients[target_uuid]
            return False
    return False

async def send_to_merchant(target_uid, message):
    """发送消息给指定商家
    
    Args:
        target_uid: 目标商家id
        message: 消息内容(JSON字符串)
    """
    merchant_key = f'merchant_{target_uid}'
    if merchant_key in clients:
        try:
            await clients[merchant_key].send(message)
            return True
        except websockets.ConnectionClosed:
            del clients[merchant_key]
            return False
    return False

async def send_to_platforms(message):
    disconnected = []
    delivered = False
    for client_key, websocket in clients.items():
        if not str(client_key).startswith('platform_'):
            continue

        try:
            await websocket.send(message)
            delivered = True
        except websockets.ConnectionClosed:
            disconnected.append(client_key)

    for client_key in disconnected:
        if client_key in clients:
            del clients[client_key]

    return delivered

async def handle_client(websocket, path=None):
    """处理客户端连接"""
    try:
        await handle_client_inner(websocket, path)
    except websockets.ConnectionClosed:
        pass
    except Exception:
        traceback.print_exc()
        try:
            await websocket.send(json.dumps({
                "type": "error",
                "error": "Connection handler failed"
            }))
            await websocket.close(1011, "Connection handler failed")
        except Exception:
            pass

async def handle_client_inner(websocket, path=None):
    """处理已建立的客户端连接"""
    try:
        init_msg = await websocket.recv()
        data = json.loads(init_msg)
        user_type = data.get("type")  # "user"、"merchant" 或 "platform"
        user_id = data.get("user_id")  # 用户的uuid或商家的uid
        auth_payload = validate_auth_token(data.get("auth_token", ""), user_type, user_id)
    except (json.JSONDecodeError, KeyError):
        await websocket.close(1003, "Invalid initial message")
        return
    except ValueError as e:
        await websocket.send(json.dumps({
            "type": "error",
            "error": str(e)
        }))
        await websocket.close(1008, str(e))
        return
    
    # 根据用户类型生成唯一标识
    if user_type == "user":
        client_key = user_id  # 使用用户的uuid
    elif user_type == "merchant":
        client_key = f'merchant_{user_id}'  # 商家使用 merchant_uid 格式
    elif user_type == "platform":
        client_key = f'platform_{user_id}'
    else:
        await websocket.close(1003, "Invalid user type")
        return
    
    clients[client_key] = websocket
    
    # 心跳检测。旧版 Python/websockets 组合在后台任务上容易提前断开连接，
    # 生产可通过 IM_HEARTBEAT_ENABLED=1 打开。
    heartbeat_enabled = os.getenv('IM_HEARTBEAT_ENABLED', '0') == '1'
    heartbeat_timeout = 60
    last_heartbeat_response = asyncio.get_event_loop().time()
    
    async def heartbeat_task():
        nonlocal last_heartbeat_response
        try:
            while True:
                await websocket.send(json.dumps({"type": "heartbeat"}))
                await asyncio.sleep(30)
                current_time = asyncio.get_event_loop().time()
                if current_time - last_heartbeat_response > heartbeat_timeout:
                    print(f"Client {client_key} heartbeat timeout")
                    if client_key in clients:
                        del clients[client_key]
                    await websocket.close(1008, "Heartbeat timeout")
                    break
        except websockets.ConnectionClosed:
            pass
    
    heartbeat_handle = create_background_task(heartbeat_task()) if heartbeat_enabled else None
    
    try:
        async for message in websocket:
            try:
                msg_data = json.loads(message)
                
                if "type" not in msg_data:
                    raise ValueError("Missing message type")
                
                if msg_data["type"] == "heartbeat":
                    last_heartbeat_response = asyncio.get_event_loop().time()
                    continue
                
                elif msg_data["type"] == "chat":
                    # 发送聊天消息
                    content, msg_type = validate_chat_payload(
                        msg_data.get("content"),
                        msg_data.get("msg_type", 1)
                    )
                    product_id = normalize_positive_int(msg_data.get("product_id", 0))
                    store_id = normalize_positive_int(msg_data.get("store_id", 0))
                    translation_metadata = validate_translation_metadata(msg_data, content, msg_type)
                    
                    if user_type == "user":
                        # 用户发消息给商家
                        target_uid = msg_data["target_uid"]
                        assert_user_chat_scope(auth_payload, target_uid, product_id, store_id)
                        from_type = 1
                        uuid = client_key
                        
                        # 保存消息
                        message_id = await save_message(from_type, target_uid, content, msg_type, uuid, product_id, store_id, translation_metadata)
                        
                        # 构造广播消息
                        broadcast_data = {
                            "type": "chat",
                            "message_id": str(message_id),
                            "from": from_type,
                            "uid": target_uid,
                            "uuid": uuid,
                            "content": content,
                            "msg_type": msg_type,
                            "product_id": product_id,
                            "store_id": store_id,
                            "timestamp": time.strftime('%Y-%m-%d %H:%M:%S', time.localtime())
                        }
                        broadcast_data.update(translation_metadata)
                        broadcast_msg = json.dumps(broadcast_data)
                        
                        # 发送给商家
                        await send_to_merchant(target_uid, broadcast_msg)
                        await send_to_platforms(broadcast_msg)
                        # 也发回给用户自己确认
                        await websocket.send(broadcast_msg)
                    
                    elif user_type == "merchant" or user_type == "platform":
                        # 商家发消息给用户
                        target_uuid = msg_data["target_uuid"]
                        from_type = 2
                        uid = msg_data.get("target_uid") if user_type == "platform" else user_id
                        uid = normalize_positive_int(uid)
                        if uid <= 0:
                            raise ValueError("Missing target_uid")
                        if product_id == 0 and store_id == 0:
                            product_id, store_id = await get_latest_chat_context(target_uuid, uid)
                        
                        # 保存消息
                        message_id = await save_message(from_type, uid, content, msg_type, target_uuid, product_id, store_id, translation_metadata)
                        
                        # 构造广播消息
                        broadcast_data = {
                            "type": "chat",
                            "message_id": str(message_id),
                            "from": from_type,
                            "uid": uid,
                            "uuid": target_uuid,
                            "content": content,
                            "msg_type": msg_type,
                            "product_id": product_id,
                            "store_id": store_id,
                            "timestamp": time.strftime('%Y-%m-%d %H:%M:%S', time.localtime())
                        }
                        broadcast_data.update(translation_metadata)
                        broadcast_msg = json.dumps(broadcast_data)
                        
                        # 发送给用户
                        await send_to_user(target_uuid, broadcast_msg)
                        if user_type == "merchant":
                            await send_to_platforms(broadcast_msg)
                        # 也发回给商家自己确认
                        await websocket.send(broadcast_msg)
                
                elif msg_data["type"] == "chat_history":
                    # 获取聊天历史
                    if user_type == "user":
                        uid = msg_data["uid"]
                        assert_user_chat_scope(auth_payload, uid)
                        uuid = client_key
                    else:  # merchant/platform
                        uid = msg_data.get("uid") if user_type == "platform" else user_id
                        uid = normalize_positive_int(uid)
                        if uid <= 0:
                            raise ValueError("Missing uid")
                        uuid = msg_data["uuid"]
                    
                    read_count = await mark_chat_read(user_type, uuid, uid)
                    history = await get_chat_history(uuid, uid)
                    await websocket.send(json.dumps({
                        "type": "chat_history",
                        "uid": uid,
                        "uuid": uuid,
                        "read_count": read_count,
                        "messages": history
                    }))

                elif msg_data["type"] == "mark_read":
                    if user_type == "user":
                        uid = msg_data["uid"]
                        assert_user_chat_scope(auth_payload, uid)
                        uuid = client_key
                    else:  # merchant/platform
                        uid = msg_data.get("uid") if user_type == "platform" else user_id
                        uid = normalize_positive_int(uid)
                        if uid <= 0:
                            raise ValueError("Missing uid")
                        uuid = msg_data["uuid"]

                    read_count = await mark_chat_read(user_type, uuid, uid)
                    await websocket.send(json.dumps({
                        "type": "read_ack",
                        "uid": uid,
                        "uuid": uuid,
                        "read_count": read_count
                    }))
                
                elif msg_data["type"] == "chat_list":
                    # 获取聊天列表
                    if user_type == "user":
                        chat_list = await get_user_chat_list(client_key)
                    elif user_type == "merchant":
                        chat_list = await get_merchant_chat_list(user_id)
                    else:  # platform
                        chat_list = await get_platform_chat_list()
                    
                    await websocket.send(json.dumps({
                        "type": "chat_list",
                        "list": chat_list
                    }))
            
            except Exception as e:
                traceback.print_exc()
                await websocket.send(json.dumps({
                    "type": "error",
                    "error": f"Processing failed: {str(e)}"
                }))
    
    finally:
        if heartbeat_handle:
            heartbeat_handle.cancel()
        if client_key in clients:
            del clients[client_key]
        print(f"Client {client_key} disconnected")

async def main():
    global db_pool
    db_pool = await create_db_pool()
    await validate_chat_table()
    host = os.getenv("IM_HOST", "0.0.0.0")
    port = int(os.getenv("IM_PORT", "8767"))
    
    server = await websockets.serve(
        handle_client,
        host,
        port,
        max_size=2 ** 20
    )
    
    print(f"DB connected: {DB_CONFIG['host']}:{DB_CONFIG['port']}/{DB_CONFIG['db']} table={CHAT_TABLE}")
    print(f"WebSocket server started on ws://{host}:{port}")
    print("单聊服务器已启动，支持用户-商家点对点通信")
    
    await server.wait_closed()

def run_async(coro):
    if hasattr(asyncio, "run"):
        return asyncio.run(coro)

    loop = asyncio.new_event_loop()
    try:
        asyncio.set_event_loop(loop)
        return loop.run_until_complete(coro)
    finally:
        loop.close()

def create_background_task(coro):
    if hasattr(asyncio, "create_task"):
        return asyncio.create_task(coro)

    return asyncio.ensure_future(coro)

if __name__ == "__main__":
    run_async(main())
