//! 跨平台 user data 路径管理
//!
//! Windows: %APPDATA%/nai-studio-desktop/
//!   ├── nai-studio.db     # SQLite 数据库
//!   ├── storage/          # 用户生成的图片 / 缩略图 / 上传
//!   │   ├── images/
//!   │   ├── thumbs/
//!   │   ├── upscales/     # Real-ESRGAN 输出
//!   │   ├── uploads/
//!   │   ├── cache/
//!   │   ├── tag-previews/
//!   │   └── tools/        # Real-ESRGAN binary + 模型
//!   │       └── realesrgan/
//!   │           ├── realesrgan-ncnn-vulkan.exe
//!   │           └── models/*.bin|param
//!   ├── logs/             # 日志
//!   └── (user-data template if fresh install)

use std::path::PathBuf;

#[derive(Debug, Clone)]
pub struct AppPaths {
    pub root: PathBuf,           // %APPDATA%/nai-studio-desktop
    pub db_file: PathBuf,        // .../nai-studio.db
    pub storage: PathBuf,        // .../storage
    pub images: PathBuf,         // .../storage/images
    pub thumbs: PathBuf,         // .../storage/thumbs
    pub upscales: PathBuf,       // .../storage/upscales
    pub uploads: PathBuf,        // .../storage/uploads
    pub cache: PathBuf,          // .../storage/cache
    pub tag_previews: PathBuf,   // .../storage/tag-previews
    pub tools: PathBuf,          // .../storage/tools
    pub logs: PathBuf,           // .../logs
}

impl AppPaths {
    pub fn new() -> Result<Self, crate::AppError> {
        // Windows: %APPDATA% = C:\Users\<user>\AppData\Roaming
        // macOS:   ~/Library/Application Support
        // Linux:   ~/.config
        let base = dirs::data_dir()
            .ok_or_else(|| crate::AppError::Config("cannot find user data dir".into()))?
            .join("nai-studio-desktop");

        let root = base.clone();
        let storage = base.join("storage");
        Ok(Self {
            root:          root.clone(),
            db_file:       root.join("nai-studio.db"),
            storage:       storage.clone(),
            images:        storage.join("images"),
            thumbs:        storage.join("thumbs"),
            upscales:      storage.join("upscales"),
            uploads:       storage.join("uploads"),
            cache:         storage.join("cache"),
            tag_previews:  storage.join("tag-previews"),
            tools:         storage.join("tools"),
            logs:          base.join("logs"),
        })
    }

    pub fn ensure_dirs(&self) -> Result<(), crate::AppError> {
        for d in [
            &self.root,
            &self.storage,
            &self.images,
            &self.thumbs,
            &self.upscales,
            &self.uploads,
            &self.cache,
            &self.tag_previews,
            &self.tools,
            &self.logs,
        ] {
            std::fs::create_dir_all(d).map_err(|e| {
                crate::AppError::Io(format!("创建目录失败 {:?}: {}", d, e))
            })?;
        }
        Ok(())
    }
}
