# OAuth là gì? (Giải thích ngắn)

Hệ thống có **hai lớp quyền** khác nhau. Đừng nhầm lẫn giữa chúng.

## 1. OAuth trên MCP server (phía ChatGPT)

Đây là bước **đăng nhập / liên kết tài khoản** giữa **ChatGPT** và **MCP server** của bạn.

| OAuth scope | Ý nghĩa |
|-------------|---------|
| `wordpress.read` | Đọc dữ liệu (bài viết, comment, media, …) |
| `wordpress.write` | Ghi dữ liệu (tạo/sửa/xóa bài, upload media, duyệt comment) |

**Chế độ Mixed (khuyến nghị):**

- Đọc: ChatGPT dùng được ngay, không cần đăng nhập.
- Ghi: ChatGPT sẽ hỏi bạn **liên kết ứng dụng (OAuth)** lần đầu khi thực hiện thao tác ghi.

OAuth **không** cho phép chọn từng quyền nhỏ (ví dụ chỉ publish, không delete). Nó chỉ có 2 mức: đọc hoặc ghi.

## 2. Scope trên WordPress plugin (phía wp-admin)

Đây là quyền **chi tiết** mà admin chọn khi tạo **Connection** trong WordPress:

- `posts.read`, `posts.create`, `posts.publish`, `posts.delete`, …
- `media.upload`, `comments.moderate`, …

Token connection nằm trên **MCP server** (`WORDPRESS_CONNECTION_TOKEN` hoặc `WORDPRESS_SITES`), **không** gửi cho ChatGPT.

### Nhiều site (multi-site)

Một MCP server có thể kết nối nhiều WordPress site. Mỗi site có token riêng trong `WORDPRESS_SITES`. ChatGPT gọi `wordpress_list_sites` rồi truyền `site` trên mỗi tool.

## Luồng hoạt động

```
ChatGPT
  → OAuth (read/write)           ← ChatGPT biết bạn có quyền đọc/ghi không
  → MCP server
  → WordPress connection token   ← Quyền chi tiết: publish, delete, upload, …
  → WordPress
```

**Ví dụ:** ChatGPT có OAuth `wordpress.write`, nhưng connection WordPress **không** có `posts.publish` → ChatGPT **không thể publish** bài viết.

## Tóm tắt

| Câu hỏi | Trả lời |
|---------|---------|
| OAuth dùng để làm gì? | Xác thực người dùng ChatGPT với MCP server |
| Ai chọn scope chi tiết? | Admin WordPress khi tạo connection |
| Publish có tách riêng trên OAuth không? | Không — kiểm soát bằng scope WordPress (`posts.publish`) |
| Token WordPress có lộ cho ChatGPT không? | Không — chỉ MCP server giữ |

Xem thêm: [CHATGPT-SETUP.md](CHATGPT-SETUP.md), [WORDPRESS-SETUP.md](WORDPRESS-SETUP.md).
