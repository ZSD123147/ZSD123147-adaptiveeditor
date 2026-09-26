# adaptiveeditor 文件结构

本文档面向二次开发者，介绍插件的源码组织、运行入口与各模块职责。**不**打包进 `.zba` 发布包（已在 [`../zbignore.txt`](../zbignore.txt) 排除）。

## 目录树

```
adaptiveeditor/
├── main.php                  唯一后台入口，所有界面与动作经 ?act= 分发
├── include.php               注册入口：挂载钩子、声明生命周期
├── plugin.xml                应用元信息
├── logo.png                  插件图标（128 × 128）
├── screenshot.png            插件缩略图（320 × 240）
├── README.md                 用户文档（站长使用）
├── zbignore.txt              打包排除清单
├── .editorconfig             编辑器格式约定（开发用，不打包）
├── docs/                     说明截图与开发者文档（不打包）
├── core/
│   ├── bootstrap.php         引导层与访问闸门
│   ├── config.php            配置 schema 与读写
│   ├── security.php          转义、CSRF、限流、JSON 响应、资源 URL
│   ├── model.php             数据访问：文章、分类、状态、标签、上传
│   ├── view.php              后台壳层与布局
│   ├── router.php            路由表与分发闸门
│   ├── core.css              设计体系（全部界面共用）
│   └── core.js               前端基座（原生 JS，零依赖）
└── views/
    ├── editor/               写文章界面
    ├── manage/               文章管理界面
    └── setting/              设置界面
```

## 模块职责速览

| 文件 | 职责 |
| --- | --- |
| `main.php` | 后台唯一入口。接收 `?act=` 参数并按 [`core/router.php`](core/router.php) 的路由表分发到对应视图与动作。 |
| `include.php` | 注册入口：声明 Z-Blog 钩子挂载点、生命周期事件、依赖检查。 |
| `core/bootstrap.php` | 引导层：在视图或动作处理前完成请求闸门（权限、令牌、方法校验）、公共函数（`ade_*`）、错误终止页（`ade_halt()`）。 |
| `core/config.php` | 配置项 schema 定义与读写封装，包含夹紧取值。 |
| `core/security.php` | 安全与响应基元：HTML 转义、CSRF 校验、限流、JSON 响应、插件资源 URL（权限判定在 `core/router.php`）。 |
| `core/model.php` | 数据访问层：文章 CRUD、分类/状态/标签封装、上传逻辑。 |
| `core/view.php` | 后台壳层：渲染页头、菜单、`<head>`、CSS/JS 引入；所有视图共享此壳。 |
| `core/router.php` | 路由表与动作闸门。 |
| `core/core.css` | 设计令牌（CSS 变量）与所有界面共享的样式基线。 |
| `core/core.js` | 前端基座：DOM 工具、事件总线、灯箱、抽屉、断点联动等零依赖实现。 |
| `views/editor/` | 写文章界面（PHP 视图 + 视图级 JS + 视图级 CSS）。 |
| `views/manage/` | 文章管理界面。 |
| `views/setting/` | 设置界面（`root` 权限）。 |

## 设计约定

- **零依赖**：插件不引入任何外部库、图标字体、CDN 资源；样式与脚本全部自持。
- **不改动模板**：插件仅作用于后台，不触碰前台模板与系统文件。
- **统一设计令牌**：颜色、字号、间距走 [`core/core.css`](core/core.css) 的 CSS 变量；视图级 CSS 只引用变量，不重复字面量。
- **服务端可降级**：列表与筛选核心逻辑在服务端渲染，关闭 JavaScript 也能用。
- **权限走内核**：所有动作通过 `CheckRights()` 判定，不绕过 Z-Blog 后台权限体系。