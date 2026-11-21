import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    ArrowLeft,
    ArrowUp,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Download,
    ExternalLink,
    File as FileIcon,
    FileAudio,
    FileCode2,
    FileImage,
    FileSpreadsheet,
    FileText,
    FileVideo,
    Folder as FolderIcon,
    FolderOpen,
    FolderTree,
    Grid2X2,
    Grid3X3,
    HardDrive,
    Layers,
    List,
    Loader2,
    Lock,
    Play,
    RefreshCw,
    RotateCcw,
    Search,
    Smartphone,
    Trash2,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatBytes, formatDateTime, formatPercent } from '@/lib/format';

type CategoryStat = {
    category: string;
    label: string;
    bytes: number;
    count: number;
};

type FolderNode = {
    id: string;
    parent_id: string | null;
    name: string;
    files_count: number;
    subfolders_count: number;
    total_bytes: number;
    updated_at: string | null;
};

type ExplorerFile = {
    id: string;
    folder_id: string | null;
    folder_path: string;
    name: string;
    category: string;
    category_label: string;
    mime_type: string;
    size_bytes: number;
    sha256: string;
    has_preview: boolean;
    has_thumbnail: boolean;
    has_custom_thumbnail: boolean;
    can_thumbnail: boolean;
    thumbnail_preset: string;
    thumbnail_preset_label: string;
    thumbnail_status: string;
    thumbnail_mime: string | null;
    thumbnail_size_bytes: number | null;
    thumbnail_url: string | null;
    content_url: string;
    download_url: string;
    media_taken_at: string | null;
    deleted_at: string | null;
    created_at: string | null;
};

type PaginatedFiles = {
    data: ExplorerFile[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type Props = {
    account: {
        id: number;
        name: string;
        email: string;
        username: string | null;
        is_admin: boolean;
        email_verified_at: string | null;
    };
    usage: {
        quota_bytes: number;
        used_bytes: number;
        reserved_bytes: number;
        available_bytes: number;
        private_bytes: number;
        private_count: number;
        trash_bytes: number;
        trash_count: number;
        total_files: number;
        categories: CategoryStat[];
    };
    folderTree: FolderNode[];
    folders: FolderNode[];
    breadcrumbs: Array<{ id: string; name: string }>;
    currentFolderId: string | null;
    files: PaginatedFiles;
    devices: Array<{
        id: string;
        name: string;
        platform: string | null;
        last_seen_at: string | null;
        last_backup_at: string | null;
        revoked: boolean;
    }>;
    sessions: Array<{
        id: string;
        space: string;
        status: string;
        total_size_bytes: number;
        received_offset: number;
        reserved_bytes: number;
        failure_reason: string | null;
        expires_at: string;
    }>;
    filters: {
        folder_id: string;
        view_mode: 'explorer' | 'all' | 'trash';
        q: string;
        category: string;
        sort: string;
        per_page: number;
    };
};

type DisplayLayout = 'list' | 'grid-md' | 'grid-sm';

const LAYOUT_STORAGE_KEY = 'gunydrive:admin:explorer_layout';

function getFileExtension(name: string): string {
    const idx = name.lastIndexOf('.');
    if (idx <= 0 || idx === name.length - 1) return '';
    return name
        .slice(idx + 1)
        .toUpperCase()
        .slice(0, 6);
}

function isTextMime(mime: string): boolean {
    return /^(text\/(plain|csv|markdown|xml)|application\/json)$/i.test(mime);
}

function isImageFile(file: ExplorerFile): boolean {
    return file.category === 'image' || file.mime_type.startsWith('image/');
}

function isVideoFile(file: ExplorerFile): boolean {
    return file.category === 'video' || file.mime_type.startsWith('video/');
}

function isAudioFile(file: ExplorerFile): boolean {
    return file.category === 'audio' || file.mime_type.startsWith('audio/');
}

function isPdfFile(file: ExplorerFile): boolean {
    return file.mime_type.toLowerCase() === 'application/pdf';
}

function CategoryFileIcon({
    file,
    className = 'size-7',
}: {
    file: ExplorerFile;
    className?: string;
}) {
    if (isImageFile(file)) {
        return <FileImage className={`${className} text-sky-500`} />;
    }
    if (isVideoFile(file)) {
        return <FileVideo className={`${className} text-violet-500`} />;
    }
    if (isAudioFile(file)) {
        return <FileAudio className={`${className} text-pink-500`} />;
    }
    if (file.category === 'archive') {
        return <Archive className={`${className} text-amber-500`} />;
    }
    if (
        file.mime_type.includes('spreadsheet') ||
        file.mime_type.includes('csv') ||
        file.mime_type.includes('excel')
    ) {
        return <FileSpreadsheet className={`${className} text-emerald-500`} />;
    }
    if (isTextMime(file.mime_type)) {
        return <FileCode2 className={`${className} text-cyan-500`} />;
    }
    if (file.category === 'document') {
        return <FileText className={`${className} text-blue-500`} />;
    }
    return <FileIcon className={`${className} text-muted-foreground`} />;
}

/**
 * Lightweight thumbnail renderer for list & grid views.
 * - Documents/Audio/Archive/Other: loads immutable shared default SVG (/thumbnails/defaults/{preset}.svg)
 * - Photos & Videos: lazy-loads resized/optimized <=320px WebP/JPEG thumbnail extracted from original file
 * NEVER fetches original file bytes in list/grid views.
 */
function LazyFileThumbnail({
    file,
    compact = false,
}: {
    file: ExplorerFile;
    compact?: boolean;
}) {
    const [failedUrl, setFailedUrl] = useState<string | null>(null);
    const [loadedUrl, setLoadedUrl] = useState<string | null>(null);
    const thumbUrl = file.thumbnail_url;
    const failed = failedUrl === thumbUrl;
    const loaded = loadedUrl === thumbUrl;
    const shouldLoadThumb = Boolean(thumbUrl) && !failed;
    const ext = getFileExtension(file.name);
    const video = isVideoFile(file);
    const showVideoOverlay =
        video &&
        (file.has_custom_thumbnail || file.thumbnail_status === 'generated');

    return (
        <div
            className={`relative flex items-center justify-center overflow-hidden bg-muted/60 ${
                compact
                    ? 'size-10 shrink-0 rounded-lg border'
                    : 'aspect-square w-full rounded-xl border'
            }`}
        >
            {shouldLoadThumb && thumbUrl ? (
                <>
                    {!loaded && (
                        <div className="absolute inset-0 animate-pulse bg-muted" />
                    )}
                    <img
                        src={thumbUrl}
                        alt={file.name}
                        loading="lazy"
                        decoding="async"
                        onLoad={() => setLoadedUrl(thumbUrl)}
                        onError={() => setFailedUrl(thumbUrl)}
                        className={`size-full object-cover transition-opacity duration-200 ${
                            loaded ? 'opacity-100' : 'opacity-0'
                        }`}
                    />
                    {showVideoOverlay && (
                        <div className="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/25">
                            <span
                                className={`flex items-center justify-center rounded-full bg-black/70 text-white shadow ${
                                    compact ? 'size-5' : 'size-10'
                                }`}
                            >
                                <Play
                                    className={
                                        compact
                                            ? 'size-2.5 fill-white'
                                            : 'ml-0.5 size-4 fill-white'
                                    }
                                />
                            </span>
                        </div>
                    )}
                </>
            ) : (
                <div className="flex flex-col items-center justify-center gap-1 p-2 text-center">
                    <CategoryFileIcon
                        file={file}
                        className={compact ? 'size-5' : 'size-9'}
                    />
                    {!compact && ext && (
                        <span className="rounded bg-background/80 px-1.5 py-0.5 font-mono text-[10px] font-bold text-muted-foreground">
                            {ext}
                        </span>
                    )}
                    {video && !compact && (
                        <span className="absolute right-2 bottom-2 flex size-7 items-center justify-center rounded-full bg-black/70 text-white">
                            <Play className="ml-0.5 size-3.5 fill-white" />
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * On-demand Original File Preview Lightbox.
 * ONLY mounts & fetches original content when a file is clicked/opened.
 */
function FilePreviewModal({
    file,
    files,
    onClose,
    onSelectFile,
    onTrash,
    onPurge,
    onRestore,
}: {
    file: ExplorerFile | null;
    files: ExplorerFile[];
    onClose: () => void;
    onSelectFile: (next: ExplorerFile) => void;
    onTrash: (file: ExplorerFile) => void;
    onPurge: (file: ExplorerFile) => void;
    onRestore: (file: ExplorerFile) => void;
}) {
    const [mediaLoading, setMediaLoading] = useState(true);
    const [zoomed, setZoomed] = useState(false);
    const [textState, setTextState] = useState<{
        fileId: string;
        content: string | null;
        error: string | null;
        truncated: boolean;
    } | null>(null);

    const currentIndex = file
        ? files.findIndex((item) => item.id === file.id)
        : -1;
    const prevFile = currentIndex > 0 ? files[currentIndex - 1] : null;
    const nextFile =
        currentIndex >= 0 && currentIndex < files.length - 1
            ? files[currentIndex + 1]
            : null;

    // Fetch original text content ONLY when a text/JSON file is opened
    useEffect(() => {
        if (!file || !isTextMime(file.mime_type)) {
            return;
        }
        let cancelled = false;
        const controller = new AbortController();

        fetch(file.content_url, { signal: controller.signal })
            .then(async (res) => {
                if (!res.ok) throw new Error('Gagal memuat berkas teks.');
                const text = await res.text();
                if (cancelled) return;
                const maxChars = 512_000;
                setTextState({
                    fileId: file.id,
                    content:
                        text.length > maxChars ? text.slice(0, maxChars) : text,
                    error: null,
                    truncated: text.length > maxChars,
                });
            })
            .catch((err: unknown) => {
                if (cancelled) return;
                setTextState({
                    fileId: file.id,
                    content: null,
                    error:
                        err instanceof Error
                            ? err.message
                            : 'Gagal memuat isi berkas.',
                    truncated: false,
                });
            });

        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [file]);

    // Keyboard navigation (Left / Right arrows)
    useEffect(() => {
        if (!file) return;
        function handleKeyDown(e: KeyboardEvent) {
            if (e.key === 'ArrowLeft' && prevFile) {
                e.preventDefault();
                setMediaLoading(true);
                setZoomed(false);
                onSelectFile(prevFile);
            } else if (e.key === 'ArrowRight' && nextFile) {
                e.preventDefault();
                setMediaLoading(true);
                setZoomed(false);
                onSelectFile(nextFile);
            }
        }
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [file, nextFile, onSelectFile, prevFile]);

    const activeText = file && textState?.fileId === file.id ? textState : null;

    return (
        <Dialog
            open={file !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="flex max-h-[92vh] max-w-5xl flex-col gap-4 overflow-hidden">
                {file && (
                    <>
                        <DialogHeader className="shrink-0">
                            <div className="flex flex-wrap items-start justify-between gap-2 pr-6">
                                <div className="min-w-0">
                                    <DialogTitle className="truncate text-base">
                                        {file.name}
                                    </DialogTitle>
                                    <DialogDescription className="mt-0.5 flex flex-wrap items-center gap-2 text-xs">
                                        <span>{file.folder_path}</span>
                                        <span>·</span>
                                        <span>{file.category_label}</span>
                                        <span>·</span>
                                        <span className="tabular-nums">
                                            {formatBytes(file.size_bytes)}
                                        </span>
                                        <span>·</span>
                                        <span>{file.mime_type}</span>
                                    </DialogDescription>
                                </div>
                                <div className="flex flex-wrap items-center gap-1.5">
                                    {prevFile && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="h-7 px-2 text-xs"
                                            onClick={() => {
                                                setMediaLoading(true);
                                                setZoomed(false);
                                                onSelectFile(prevFile);
                                            }}
                                        >
                                            <ChevronLeft className="size-3.5" />
                                        </Button>
                                    )}
                                    {nextFile && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="h-7 px-2 text-xs"
                                            onClick={() => {
                                                setMediaLoading(true);
                                                setZoomed(false);
                                                onSelectFile(nextFile);
                                            }}
                                        >
                                            <ChevronRight className="size-3.5" />
                                        </Button>
                                    )}
                                    {isImageFile(file) && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="h-7 px-2.5 text-xs"
                                            onClick={() => setZoomed((z) => !z)}
                                        >
                                            {zoomed ? (
                                                <ZoomOut className="size-3.5" />
                                            ) : (
                                                <ZoomIn className="size-3.5" />
                                            )}
                                            {zoomed ? 'Pas Layar' : 'Perbesar'}
                                        </Button>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-7 px-2.5 text-xs"
                                        asChild
                                    >
                                        <a
                                            href={file.content_url}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <ExternalLink className="size-3.5" />
                                            Tab Baru
                                        </a>
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="default"
                                        className="h-7 px-2.5 text-xs"
                                        asChild
                                    >
                                        <a href={file.download_url} download>
                                            <Download className="size-3.5" />
                                            Unduh Asli
                                        </a>
                                    </Button>
                                </div>
                            </div>
                        </DialogHeader>

                        {/* On-Demand Original Preview Stage */}
                        <div className="relative flex min-h-72 flex-1 items-center justify-center overflow-auto rounded-xl border bg-muted/40 p-3">
                            {isImageFile(file) ? (
                                <>
                                    {mediaLoading && (
                                        <div className="absolute inset-0 flex items-center justify-center bg-background/60 backdrop-blur-xs">
                                            <div className="flex items-center gap-2 rounded-full border bg-card px-4 py-2 text-xs font-medium shadow">
                                                <Loader2 className="size-4 animate-spin text-primary" />
                                                Memuat gambar asli…
                                            </div>
                                        </div>
                                    )}
                                    <img
                                        key={file.id}
                                        src={file.content_url}
                                        alt={file.name}
                                        onLoad={() => setMediaLoading(false)}
                                        onError={() => setMediaLoading(false)}
                                        className={
                                            zoomed
                                                ? 'max-w-none cursor-zoom-out rounded-lg'
                                                : 'max-h-[62vh] max-w-full cursor-zoom-in rounded-lg object-contain'
                                        }
                                        onClick={() => setZoomed((z) => !z)}
                                    />
                                </>
                            ) : isVideoFile(file) ? (
                                <video
                                    key={file.id}
                                    src={file.content_url}
                                    controls
                                    autoPlay
                                    playsInline
                                    preload="metadata"
                                    className="max-h-[62vh] w-full rounded-lg bg-black"
                                />
                            ) : isAudioFile(file) ? (
                                <div className="flex w-full max-w-md flex-col items-center gap-4 rounded-2xl border bg-card p-6 text-center">
                                    <div className="flex size-16 items-center justify-center rounded-2xl bg-pink-500/15">
                                        <FileAudio className="size-8 text-pink-500" />
                                    </div>
                                    <div>
                                        <div className="font-bold">
                                            {file.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {formatBytes(file.size_bytes)} ·{' '}
                                            {file.mime_type}
                                        </div>
                                    </div>
                                    <audio
                                        key={file.id}
                                        src={file.content_url}
                                        controls
                                        autoPlay
                                        className="w-full"
                                    />
                                </div>
                            ) : isPdfFile(file) ? (
                                <iframe
                                    key={file.id}
                                    src={file.content_url}
                                    title={file.name}
                                    className="h-[62vh] w-full rounded-lg bg-white"
                                />
                            ) : isTextMime(file.mime_type) ? (
                                <div className="flex h-[60vh] w-full flex-col overflow-hidden rounded-lg border bg-card">
                                    {!activeText ? (
                                        <div className="flex flex-1 items-center justify-center gap-2 text-xs text-muted-foreground">
                                            <Loader2 className="size-4 animate-spin text-primary" />
                                            Mengambil isi berkas asli…
                                        </div>
                                    ) : activeText.error ? (
                                        <div className="flex flex-1 items-center justify-center text-xs text-destructive">
                                            {activeText.error}
                                        </div>
                                    ) : (
                                        <>
                                            {activeText.truncated && (
                                                <div className="border-b bg-amber-500/10 px-3 py-1.5 text-[11px] text-amber-700 dark:text-amber-300">
                                                    Menampilkan 512 KB pertama.
                                                    Unduh berkas asli untuk
                                                    melihat isi penuh.
                                                </div>
                                            )}
                                            <pre className="flex-1 overflow-auto p-3 font-mono text-xs whitespace-pre-wrap">
                                                {activeText.content}
                                            </pre>
                                        </>
                                    )}
                                </div>
                            ) : (
                                <div className="flex max-w-md flex-col items-center gap-4 rounded-2xl border bg-card p-8 text-center">
                                    {file.thumbnail_url ? (
                                        <img
                                            src={file.thumbnail_url}
                                            alt={file.thumbnail_preset_label}
                                            className="size-24 rounded-2xl border shadow-xs"
                                        />
                                    ) : (
                                        <CategoryFileIcon
                                            file={file}
                                            className="size-12"
                                        />
                                    )}
                                    <div className="space-y-1">
                                        <div className="font-bold break-all">
                                            {file.name}
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            Pratinjau langsung belum didukung
                                            untuk tipe{' '}
                                            <code>{file.mime_type}</code>. Anda
                                            dapat mengunduh berkas asli di bawah
                                            ini.
                                        </p>
                                    </div>
                                    <Button asChild className="rounded-full">
                                        <a href={file.download_url} download>
                                            <Download className="size-4" />
                                            Unduh Berkas Asli (
                                            {formatBytes(file.size_bytes)})
                                        </a>
                                    </Button>
                                </div>
                            )}
                        </div>

                        {/* Metadata + Moderation Footer */}
                        <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 border-t pt-3 text-xs text-muted-foreground">
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                                <span>
                                    Diunggah:{' '}
                                    <strong className="text-foreground">
                                        {formatDateTime(file.created_at)}
                                    </strong>
                                </span>
                                {file.media_taken_at && (
                                    <span>
                                        Diambil:{' '}
                                        <strong className="text-foreground">
                                            {formatDateTime(
                                                file.media_taken_at,
                                            )}
                                        </strong>
                                    </span>
                                )}
                                <span>
                                    Thumbnail:{' '}
                                    <strong className="text-foreground">
                                        {file.has_custom_thumbnail
                                            ? `Dioptimasi${
                                                  file.thumbnail_size_bytes
                                                      ? ` (${formatBytes(file.thumbnail_size_bytes)}${
                                                            file.thumbnail_mime ===
                                                            'image/webp'
                                                                ? ' · WebP'
                                                                : ' · JPEG'
                                                        })`
                                                      : ''
                                              }`
                                            : `Default (${file.thumbnail_preset_label})`}
                                    </strong>
                                </span>
                                <span className="font-mono" title={file.sha256}>
                                    SHA-256: {file.sha256.slice(0, 12)}…
                                </span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                {file.deleted_at ? (
                                    <>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="h-7 text-xs"
                                            onClick={() => onRestore(file)}
                                        >
                                            <RotateCcw className="size-3.5" />
                                            Pulihkan
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            className="h-7 text-xs"
                                            onClick={() => onPurge(file)}
                                        >
                                            <Trash2 className="size-3.5" />
                                            Hapus Permanen
                                        </Button>
                                    </>
                                ) : (
                                    <>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="h-7 text-xs"
                                            onClick={() => onTrash(file)}
                                        >
                                            <Trash2 className="size-3.5" />
                                            Pindah ke Sampah
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            className="h-7 text-xs"
                                            onClick={() => onPurge(file)}
                                        >
                                            Purge
                                        </Button>
                                    </>
                                )}
                            </div>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

/** Recursive Sidebar Folder Tree Node */
function FolderTreeBranch({
    parentId,
    childrenByParent,
    currentFolderId,
    expandedIds,
    onToggleExpand,
    onSelectFolder,
    depth = 0,
}: {
    parentId: string | null;
    childrenByParent: Map<string | null, FolderNode[]>;
    currentFolderId: string | null;
    expandedIds: Set<string>;
    onToggleExpand: (id: string) => void;
    onSelectFolder: (id: string) => void;
    depth?: number;
}) {
    const nodes = childrenByParent.get(parentId) ?? [];
    if (nodes.length === 0) return null;

    return (
        <ul className={depth > 0 ? 'ml-3 border-l pl-2' : 'space-y-0.5'}>
            {nodes.map((node) => {
                const hasKids =
                    (childrenByParent.get(node.id)?.length ?? 0) > 0;
                const expanded = expandedIds.has(node.id);
                const active = currentFolderId === node.id;

                return (
                    <li key={node.id}>
                        <div
                            className={`group flex items-center gap-1 rounded-lg px-1.5 py-1 text-xs transition ${
                                active
                                    ? 'bg-primary/15 font-semibold text-primary'
                                    : 'text-foreground hover:bg-muted/60'
                            }`}
                        >
                            {hasKids ? (
                                <button
                                    type="button"
                                    aria-label={
                                        expanded
                                            ? `Tutup ${node.name}`
                                            : `Buka ${node.name}`
                                    }
                                    onClick={() => onToggleExpand(node.id)}
                                    className="flex size-4 shrink-0 items-center justify-center rounded hover:bg-muted"
                                >
                                    {expanded ? (
                                        <ChevronDown className="size-3" />
                                    ) : (
                                        <ChevronRight className="size-3" />
                                    )}
                                </button>
                            ) : (
                                <span className="size-4 shrink-0" />
                            )}
                            <button
                                type="button"
                                onClick={() => onSelectFolder(node.id)}
                                className="flex min-w-0 flex-1 items-center gap-1.5 text-left"
                            >
                                {active ? (
                                    <FolderOpen className="size-3.5 shrink-0 text-primary" />
                                ) : (
                                    <FolderIcon className="size-3.5 shrink-0 text-amber-500" />
                                )}
                                <span className="truncate">{node.name}</span>
                                <span className="ml-auto shrink-0 text-[10px] text-muted-foreground tabular-nums">
                                    {node.files_count}
                                </span>
                            </button>
                        </div>
                        {hasKids && expanded && (
                            <FolderTreeBranch
                                parentId={node.id}
                                childrenByParent={childrenByParent}
                                currentFolderId={currentFolderId}
                                expandedIds={expandedIds}
                                onToggleExpand={onToggleExpand}
                                onSelectFolder={onSelectFolder}
                                depth={depth + 1}
                            />
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

export default function UserStoragePage({
    account,
    usage,
    folderTree,
    folders,
    breadcrumbs,
    currentFolderId,
    files,
    devices,
    sessions,
    filters,
}: Props) {
    const [layout, setLayout] = useState<DisplayLayout>(() => {
        if (typeof window === 'undefined') return 'grid-md';
        const saved = window.localStorage.getItem(LAYOUT_STORAGE_KEY);
        if (saved === 'list' || saved === 'grid-md' || saved === 'grid-sm') {
            return saved;
        }
        return 'grid-md';
    });
    const [searchText, setSearchText] = useState(filters.q);
    const [previewFile, setPreviewFile] = useState<ExplorerFile | null>(null);
    const [showDevices, setShowDevices] = useState(false);

    // Pre-expand ancestor folders of the active breadcrumb
    const [expandedIds, setExpandedIds] = useState<Set<string>>(() => {
        const init = new Set<string>();
        for (const crumb of breadcrumbs) {
            init.add(crumb.id);
        }
        // Also expand top-level folders if there are few so backup structure is visible right away
        if (folderTree.length <= 20) {
            for (const f of folderTree) {
                if (f.parent_id === null) init.add(f.id);
            }
        }
        return init;
    });

    const childrenByParent = useMemo(() => {
        const map = new Map<string | null, FolderNode[]>();
        for (const node of folderTree) {
            const list = map.get(node.parent_id) ?? [];
            list.push(node);
            map.set(node.parent_id, list);
        }
        return map;
    }, [folderTree]);

    const usedPct = formatPercent(
        usage.used_bytes,
        Math.max(1, usage.quota_bytes),
    );
    const baseUrl = `/users/${account.id}/storage`;

    function changeLayout(next: DisplayLayout) {
        setLayout(next);
        if (typeof window !== 'undefined') {
            window.localStorage.setItem(LAYOUT_STORAGE_KEY, next);
        }
    }

    function navigateExplorer(
        next: Partial<Props['filters']> & { page?: number },
    ) {
        const merged = { ...filters, ...next };
        const params: Record<string, string | number> = {
            view_mode: merged.view_mode,
        };
        if (merged.view_mode === 'explorer' && merged.folder_id) {
            params.folder_id = merged.folder_id;
        }
        if (merged.q) params.q = merged.q;
        if (merged.category && merged.category !== 'all') {
            params.category = merged.category;
        }
        if (merged.sort && merged.sort !== 'newest') {
            params.sort = merged.sort;
        }
        if (merged.per_page !== 24) {
            params.per_page = merged.per_page;
        }
        if (next.page && next.page > 1) {
            params.page = next.page;
        }

        router.get(baseUrl, params, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function openFolder(folderId: string | null) {
        if (folderId) {
            setExpandedIds((prev) => {
                const copy = new Set(prev);
                copy.add(folderId);
                return copy;
            });
        }
        setSearchText('');
        navigateExplorer({
            view_mode: 'explorer',
            folder_id: folderId ?? '',
            q: '',
            page: 1,
        });
    }

    function goUpFolder() {
        if (breadcrumbs.length <= 1) {
            openFolder(null);
            return;
        }
        openFolder(breadcrumbs[breadcrumbs.length - 2].id);
    }

    function toggleExpand(id: string) {
        setExpandedIds((prev) => {
            const copy = new Set(prev);
            if (copy.has(id)) {
                copy.delete(id);
            } else {
                copy.add(id);
            }
            return copy;
        });
    }

    function handleSearchSubmit(e: React.FormEvent) {
        e.preventDefault();
        navigateExplorer({ q: searchText.trim(), page: 1 });
    }

    function handleTrashFile(file: ExplorerFile) {
        if (!window.confirm(`Pindahkan "${file.name}" ke Sampah?`)) return;
        router.delete(`/users/${account.id}/files/${file.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                if (previewFile?.id === file.id) setPreviewFile(null);
            },
        });
    }

    function handlePurgeFile(file: ExplorerFile) {
        if (
            !window.confirm(
                `Hapus permanen "${file.name}"? Ruang disk akan dibebaskan dan tidak dapat dipulihkan.`,
            )
        ) {
            return;
        }
        router.delete(`/users/${account.id}/files/${file.id}?permanent=1`, {
            preserveScroll: true,
            onSuccess: () => {
                if (previewFile?.id === file.id) setPreviewFile(null);
            },
        });
    }

    function handleRestoreFile(file: ExplorerFile) {
        router.post(
            `/users/${account.id}/files/${file.id}/restore`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (previewFile?.id === file.id) setPreviewFile(null);
                },
            },
        );
    }

    function handleRecalculate() {
        router.post(
            `/users/${account.id}/storage/recalculate`,
            {},
            { preserveScroll: true },
        );
    }

    function handlePurgeTrash() {
        if (
            !window.confirm(
                `Kosongkan seluruh Sampah (${usage.trash_count} berkas) milik ${account.name} secara permanen?`,
            )
        ) {
            return;
        }
        router.post(
            `/users/${account.id}/trash/purge`,
            {},
            { preserveScroll: true },
        );
    }

    const gridClass =
        layout === 'grid-sm'
            ? 'grid grid-cols-3 gap-2.5 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-7'
            : 'grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5';

    return (
        <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
            <Head title={`Storage & Explorer · ${account.name}`} />

            {/* Header + Back Link + Quota Actions */}
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="space-y-1.5">
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            className="h-8 rounded-full px-3 text-xs"
                            asChild
                        >
                            <Link href="/users">
                                <ArrowLeft className="size-3.5" />
                                Pengguna &amp; Storage
                            </Link>
                        </Button>
                        <Badge
                            variant={account.is_admin ? 'default' : 'secondary'}
                        >
                            {account.is_admin ? 'Admin' : 'Pengguna'}
                        </Badge>
                        {account.email_verified_at && (
                            <Badge variant="outline">Terverifikasi</Badge>
                        )}
                    </div>
                    <h1 className="text-2xl font-extrabold tracking-tight md:text-3xl">
                        {account.name}
                    </h1>
                    <p className="text-xs text-muted-foreground sm:text-sm">
                        {account.email}
                        {account.username
                            ? ` · @${account.username}`
                            : ''} ·{' '}
                        {folderTree.length} folder backup ·{' '}
                        {usage.total_files.toLocaleString('id-ID')} berkas ·{' '}
                        {devices.length} perangkat
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        className="rounded-full"
                        onClick={() => setShowDevices((v) => !v)}
                    >
                        <Smartphone className="size-3.5" />
                        Perangkat &amp; Transfer ({devices.length})
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        className="rounded-full"
                        onClick={handleRecalculate}
                    >
                        <RefreshCw className="size-3.5" />
                        Sinkronkan Kuota
                    </Button>
                    {usage.trash_count > 0 && (
                        <Button
                            size="sm"
                            variant="destructive"
                            className="rounded-full"
                            onClick={handlePurgeTrash}
                        >
                            <Trash2 className="size-3.5" />
                            Kosongkan Sampah ({usage.trash_count})
                        </Button>
                    )}
                </div>
            </div>

            {/* Compact Storage Meter + Clickable Category Breakdown */}
            <Card className="rounded-2xl">
                <CardContent className="space-y-4 pt-5">
                    <div className="space-y-2">
                        <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                            <div className="flex items-center gap-2">
                                <HardDrive className="size-4 text-primary" />
                                <span className="text-lg font-extrabold tabular-nums">
                                    {formatBytes(usage.used_bytes)}
                                </span>
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    terpakai dari{' '}
                                    {formatBytes(usage.quota_bytes)} ({usedPct}
                                    %)
                                </span>
                            </div>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                Tersedia {formatBytes(usage.available_bytes)} ·
                                Reservasi {formatBytes(usage.reserved_bytes)}
                            </span>
                        </div>
                        <div className="h-2.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className={`h-full rounded-full ${
                                    usedPct >= 90
                                        ? 'bg-destructive'
                                        : 'gd-meter'
                                }`}
                                style={{ width: `${Math.min(100, usedPct)}%` }}
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-8">
                        {usage.categories.map((cat) => {
                            const active =
                                filters.view_mode !== 'trash' &&
                                filters.category === cat.category;
                            return (
                                <button
                                    key={cat.category}
                                    type="button"
                                    onClick={() =>
                                        navigateExplorer({
                                            view_mode:
                                                filters.view_mode === 'trash'
                                                    ? 'all'
                                                    : filters.view_mode,
                                            category: active
                                                ? 'all'
                                                : cat.category,
                                            page: 1,
                                        })
                                    }
                                    className={`rounded-xl border p-2.5 text-left transition ${
                                        active
                                            ? 'border-primary bg-primary/10 ring-1 ring-primary'
                                            : 'hover:border-primary/40 hover:bg-muted/40'
                                    }`}
                                >
                                    <div className="text-[11px] text-muted-foreground">
                                        {cat.label}
                                    </div>
                                    <div className="mt-0.5 text-xs font-bold tabular-nums">
                                        {formatBytes(cat.bytes)}
                                    </div>
                                    <div className="text-[10px] text-muted-foreground tabular-nums">
                                        {cat.count} berkas
                                    </div>
                                </button>
                            );
                        })}

                        <div
                            className="rounded-xl border bg-muted/30 p-2.5"
                            title="Nama dan isi Folder Privat selalu dirahasiakan dari panel admin"
                        >
                            <div className="inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                                <Lock className="size-3 text-primary" />
                                Privat
                            </div>
                            <div className="mt-0.5 text-xs font-bold tabular-nums">
                                {formatBytes(usage.private_bytes)}
                            </div>
                            <div className="text-[10px] text-muted-foreground tabular-nums">
                                {usage.private_count} rahasia
                            </div>
                        </div>

                        <button
                            type="button"
                            onClick={() =>
                                navigateExplorer({
                                    view_mode:
                                        filters.view_mode === 'trash'
                                            ? 'explorer'
                                            : 'trash',
                                    category: 'all',
                                    page: 1,
                                })
                            }
                            className={`rounded-xl border p-2.5 text-left transition ${
                                filters.view_mode === 'trash'
                                    ? 'border-destructive bg-destructive/10 ring-1 ring-destructive'
                                    : 'hover:border-destructive/40 hover:bg-muted/40'
                            }`}
                        >
                            <div className="inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                                <Trash2 className="size-3" />
                                Sampah
                            </div>
                            <div className="mt-0.5 text-xs font-bold tabular-nums">
                                {formatBytes(usage.trash_bytes)}
                            </div>
                            <div className="text-[10px] text-muted-foreground tabular-nums">
                                {usage.trash_count} berkas
                            </div>
                        </button>
                    </div>
                </CardContent>
            </Card>

            {/* Collapsible Devices & Active Upload Sessions Panel */}
            {showDevices && (
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className="rounded-2xl">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <Smartphone className="size-4 text-primary" />
                                Perangkat Terhubung ({devices.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {devices.length === 0 ? (
                                <p className="text-xs text-muted-foreground">
                                    Belum ada perangkat terdaftar.
                                </p>
                            ) : (
                                <div className="divide-y text-xs">
                                    {devices.map((d) => (
                                        <div
                                            key={d.id}
                                            className="flex items-center justify-between gap-2 py-2"
                                        >
                                            <div>
                                                <div className="font-semibold">
                                                    {d.name}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {d.platform ?? 'mobile'} ·
                                                    Backup:{' '}
                                                    {formatDateTime(
                                                        d.last_backup_at,
                                                    )}
                                                </div>
                                            </div>
                                            <Badge
                                                variant={
                                                    d.revoked
                                                        ? 'destructive'
                                                        : 'secondary'
                                                }
                                            >
                                                {d.revoked
                                                    ? 'Dicabut'
                                                    : 'Aktif'}
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="rounded-2xl">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">
                                Sesi Transfer Terbuka / Gagal ({sessions.length}
                                )
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {sessions.length === 0 ? (
                                <p className="text-xs text-muted-foreground">
                                    Tidak ada sesi unggahan macet atau gagal.
                                </p>
                            ) : (
                                <div className="divide-y text-xs">
                                    {sessions.map((s) => (
                                        <div
                                            key={s.id}
                                            className="flex items-center justify-between gap-2 py-2"
                                        >
                                            <div>
                                                <div className="font-medium tabular-nums">
                                                    {formatBytes(
                                                        s.received_offset,
                                                    )}{' '}
                                                    /{' '}
                                                    {formatBytes(
                                                        s.total_size_bytes,
                                                    )}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    Ruang: {s.space} ·
                                                    Reservasi:{' '}
                                                    {formatBytes(
                                                        s.reserved_bytes,
                                                    )}
                                                </div>
                                            </div>
                                            <Badge
                                                variant={
                                                    s.status === 'open'
                                                        ? 'secondary'
                                                        : 'destructive'
                                                }
                                            >
                                                {s.status}
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            )}

            {/* Main 2-Pane Backup File Explorer */}
            <div className="grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)]">
                {/* Left Sidebar: Backup Folder Tree */}
                <Card className="h-fit rounded-2xl lg:sticky lg:top-4">
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-2 text-sm">
                            <FolderTree className="size-4 text-primary" />
                            Struktur Folder Backup
                        </CardTitle>
                        <CardDescription className="text-[11px]">
                            Hanya menampilkan ruang biasa (Folder Privat
                            terisolasi)
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {/* Quick Navigation Modes */}
                        <div className="space-y-1 border-b pb-2.5 text-xs">
                            <button
                                type="button"
                                onClick={() => openFolder(null)}
                                className={`flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition ${
                                    filters.view_mode === 'explorer' &&
                                    currentFolderId === null
                                        ? 'bg-primary/15 font-semibold text-primary'
                                        : 'hover:bg-muted/60'
                                }`}
                            >
                                <FolderOpen className="size-3.5 shrink-0" />
                                <span>Root (Folder Utama)</span>
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    navigateExplorer({
                                        view_mode: 'all',
                                        folder_id: '',
                                        page: 1,
                                    })
                                }
                                className={`flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition ${
                                    filters.view_mode === 'all'
                                        ? 'bg-primary/15 font-semibold text-primary'
                                        : 'hover:bg-muted/60'
                                }`}
                            >
                                <Layers className="size-3.5 shrink-0" />
                                <span>Semua Berkas (Datar)</span>
                                <span className="ml-auto text-[10px] text-muted-foreground tabular-nums">
                                    {usage.total_files - usage.private_count}
                                </span>
                            </button>
                            <button
                                type="button"
                                onClick={() =>
                                    navigateExplorer({
                                        view_mode: 'trash',
                                        folder_id: '',
                                        category: 'all',
                                        page: 1,
                                    })
                                }
                                className={`flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition ${
                                    filters.view_mode === 'trash'
                                        ? 'bg-destructive/15 font-semibold text-destructive'
                                        : 'hover:bg-muted/60'
                                }`}
                            >
                                <Trash2 className="size-3.5 shrink-0" />
                                <span>Sampah</span>
                                <span className="ml-auto text-[10px] text-muted-foreground tabular-nums">
                                    {usage.trash_count}
                                </span>
                            </button>
                        </div>

                        {/* Hierarchical Folder Tree */}
                        <div className="max-h-[420px] overflow-y-auto pr-1">
                            {folderTree.length === 0 ? (
                                <p className="px-2 py-3 text-xs text-muted-foreground">
                                    Pengguna belum memiliki subfolder backup.
                                </p>
                            ) : (
                                <FolderTreeBranch
                                    parentId={null}
                                    childrenByParent={childrenByParent}
                                    currentFolderId={
                                        filters.view_mode === 'explorer'
                                            ? currentFolderId
                                            : null
                                    }
                                    expandedIds={expandedIds}
                                    onToggleExpand={toggleExpand}
                                    onSelectFolder={(id) => openFolder(id)}
                                />
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Right Pane: File Explorer Toolbar + Breadcrumbs + Grid/List */}
                <Card className="min-w-0 rounded-2xl">
                    <CardHeader className="gap-3 pb-3">
                        {/* Breadcrumbs + Up Button */}
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <nav
                                aria-label="Breadcrumb folder"
                                className="flex flex-wrap items-center gap-1 text-xs"
                            >
                                {filters.view_mode === 'explorer' &&
                                    currentFolderId !== null && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            className="mr-1 h-7 px-2 text-xs"
                                            onClick={goUpFolder}
                                        >
                                            <ArrowUp className="size-3.5" />
                                            Naik
                                        </Button>
                                    )}

                                {filters.view_mode === 'all' ? (
                                    <span className="inline-flex items-center gap-1.5 font-bold text-foreground">
                                        <Layers className="size-3.5 text-primary" />
                                        Semua Berkas Ruang Biasa
                                    </span>
                                ) : filters.view_mode === 'trash' ? (
                                    <span className="inline-flex items-center gap-1.5 font-bold text-destructive">
                                        <Trash2 className="size-3.5" />
                                        Sampah Pengguna
                                    </span>
                                ) : (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() => openFolder(null)}
                                            className={`rounded px-1.5 py-0.5 transition hover:bg-muted ${
                                                currentFolderId === null
                                                    ? 'font-bold text-foreground'
                                                    : 'text-muted-foreground'
                                            }`}
                                        >
                                            Root
                                        </button>
                                        {breadcrumbs.map((crumb, idx) => {
                                            const isLast =
                                                idx === breadcrumbs.length - 1;
                                            return (
                                                <span
                                                    key={crumb.id}
                                                    className="inline-flex items-center gap-1"
                                                >
                                                    <ChevronRight className="size-3 text-muted-foreground" />
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            openFolder(crumb.id)
                                                        }
                                                        className={`rounded px-1.5 py-0.5 transition hover:bg-muted ${
                                                            isLast
                                                                ? 'font-bold text-foreground'
                                                                : 'text-muted-foreground'
                                                        }`}
                                                    >
                                                        {crumb.name}
                                                    </button>
                                                </span>
                                            );
                                        })}
                                    </>
                                )}
                            </nav>

                            {/* View Layout Switcher (List / Medium Grid / Compact Grid) */}
                            <div className="inline-flex items-center rounded-full border bg-muted/40 p-0.5">
                                {(
                                    [
                                        ['list', List, 'Daftar'],
                                        ['grid-md', Grid2X2, 'Grid Sedang'],
                                        ['grid-sm', Grid3X3, 'Grid Rapat'],
                                    ] as const
                                ).map(([mode, Icon, label]) => (
                                    <button
                                        key={mode}
                                        type="button"
                                        title={label}
                                        aria-label={label}
                                        onClick={() => changeLayout(mode)}
                                        className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium transition ${
                                            layout === mode
                                                ? 'bg-primary text-primary-foreground shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        <Icon className="size-3.5" />
                                        <span className="hidden sm:inline">
                                            {label}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Search, Category, Sorting & Per-Page Controls */}
                        <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-muted/30 p-2.5">
                            <form
                                onSubmit={handleSearchSubmit}
                                className="flex min-w-48 flex-1 items-center gap-1.5"
                            >
                                <div className="relative flex-1">
                                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        aria-label="Cari folder atau berkas"
                                        value={searchText}
                                        onChange={(e) =>
                                            setSearchText(e.target.value)
                                        }
                                        placeholder="Cari nama folder atau berkas…"
                                        className="h-8 bg-card pl-8 text-xs"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="secondary"
                                    className="h-8 px-3 text-xs"
                                >
                                    Cari
                                </Button>
                            </form>

                            <select
                                aria-label="Filter kategori berkas"
                                value={filters.category}
                                onChange={(e) =>
                                    navigateExplorer({
                                        category: e.target.value,
                                        page: 1,
                                    })
                                }
                                className="h-8 rounded-md border bg-card px-2.5 text-xs"
                            >
                                <option value="all">Semua Kategori</option>
                                <option value="image">Foto</option>
                                <option value="video">Video</option>
                                <option value="document">Dokumen</option>
                                <option value="audio">Audio</option>
                                <option value="archive">Arsip</option>
                                <option value="other">Lainnya</option>
                            </select>

                            <select
                                aria-label="Urutkan berkas"
                                value={filters.sort}
                                onChange={(e) =>
                                    navigateExplorer({
                                        sort: e.target.value,
                                        page: 1,
                                    })
                                }
                                className="h-8 rounded-md border bg-card px-2.5 text-xs"
                            >
                                <option value="newest">Terbaru</option>
                                <option value="oldest">Terlama</option>
                                <option value="name_asc">Nama A–Z</option>
                                <option value="name_desc">Nama Z–A</option>
                                <option value="size_desc">
                                    Ukuran Terbesar
                                </option>
                                <option value="size_asc">
                                    Ukuran Terkecil
                                </option>
                            </select>

                            <select
                                aria-label="Jumlah item per halaman"
                                value={String(filters.per_page)}
                                onChange={(e) =>
                                    navigateExplorer({
                                        per_page: Number(e.target.value),
                                        page: 1,
                                    })
                                }
                                className="h-8 rounded-md border bg-card px-2 text-xs"
                            >
                                <option value="24">24 / hal</option>
                                <option value="48">48 / hal</option>
                                <option value="96">96 / hal</option>
                            </select>
                        </div>
                    </CardHeader>

                    <CardContent className="space-y-5">
                        {folders.length === 0 && files.data.length === 0 ? (
                            <div className="rounded-xl border border-dashed p-12 text-center text-sm text-muted-foreground">
                                {filters.q || filters.category !== 'all'
                                    ? 'Tidak ada folder atau berkas yang cocok dengan filter ini.'
                                    : filters.view_mode === 'trash'
                                      ? 'Sampah pengguna kosong.'
                                      : 'Folder ini belum berisi subfolder maupun berkas.'}
                            </div>
                        ) : layout === 'list' ? (
                            /* LIST / TABLE VIEW (with lightweight 40x40 thumbnails only) */
                            <div className="overflow-x-auto rounded-xl border">
                                <table className="w-full text-left text-xs">
                                    <thead className="border-b bg-muted/70 text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2 font-semibold">
                                                Nama
                                            </th>
                                            <th className="hidden px-3 py-2 font-semibold md:table-cell">
                                                Lokasi / Tipe
                                            </th>
                                            <th className="px-3 py-2 text-right font-semibold">
                                                Ukuran
                                            </th>
                                            <th className="hidden px-3 py-2 font-semibold sm:table-cell">
                                                Diperbarui
                                            </th>
                                            <th className="px-3 py-2 text-right font-semibold">
                                                Aksi
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y tabular-nums">
                                        {folders.map((folder) => (
                                            <tr
                                                key={`folder-${folder.id}`}
                                                onClick={() =>
                                                    openFolder(folder.id)
                                                }
                                                className="cursor-pointer transition hover:bg-muted/50"
                                            >
                                                <td className="px-3 py-2">
                                                    <div className="flex items-center gap-2.5">
                                                        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-amber-500/10">
                                                            <FolderIcon className="size-5 text-amber-500" />
                                                        </div>
                                                        <div className="min-w-0">
                                                            <div className="truncate font-semibold">
                                                                {folder.name}
                                                            </div>
                                                            <div className="text-[11px] text-muted-foreground">
                                                                {folder.subfolders_count >
                                                                0
                                                                    ? `${folder.subfolders_count} subfolder · `
                                                                    : ''}
                                                                {
                                                                    folder.files_count
                                                                }{' '}
                                                                berkas
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="hidden px-3 py-2 md:table-cell">
                                                    <Badge variant="outline">
                                                        Folder
                                                    </Badge>
                                                </td>
                                                <td className="px-3 py-2 text-right font-semibold whitespace-nowrap">
                                                    {formatBytes(
                                                        folder.total_bytes,
                                                    )}
                                                </td>
                                                <td className="hidden px-3 py-2 whitespace-nowrap text-muted-foreground sm:table-cell">
                                                    {formatDateTime(
                                                        folder.updated_at,
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        className="h-7 px-2 text-xs"
                                                    >
                                                        Buka
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}

                                        {files.data.map((file) => (
                                            <tr
                                                key={file.id}
                                                onClick={() =>
                                                    setPreviewFile(file)
                                                }
                                                className="cursor-pointer transition hover:bg-muted/50"
                                            >
                                                <td className="px-3 py-2">
                                                    <div className="flex items-center gap-2.5">
                                                        <LazyFileThumbnail
                                                            file={file}
                                                            compact
                                                        />
                                                        <div className="min-w-0">
                                                            <div className="max-w-xs truncate font-medium">
                                                                {file.name}
                                                            </div>
                                                            <div className="text-[11px] text-muted-foreground">
                                                                {file.mime_type}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="hidden px-3 py-2 md:table-cell">
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        <Badge variant="secondary">
                                                            {
                                                                file.category_label
                                                            }
                                                        </Badge>
                                                        {filters.view_mode !==
                                                            'explorer' && (
                                                            <span className="max-w-40 truncate text-[11px] text-muted-foreground">
                                                                {
                                                                    file.folder_path
                                                                }
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2 text-right font-semibold whitespace-nowrap">
                                                    {formatBytes(
                                                        file.size_bytes,
                                                    )}
                                                </td>
                                                <td className="hidden px-3 py-2 whitespace-nowrap text-muted-foreground sm:table-cell">
                                                    {formatDateTime(
                                                        file.created_at,
                                                    )}
                                                </td>
                                                <td
                                                    className="px-3 py-2 text-right whitespace-nowrap"
                                                    onClick={(e) =>
                                                        e.stopPropagation()
                                                    }
                                                >
                                                    <div className="inline-flex items-center gap-1">
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="ghost"
                                                            className="h-7 px-2 text-xs"
                                                            asChild
                                                        >
                                                            <a
                                                                href={
                                                                    file.download_url
                                                                }
                                                                download
                                                                title="Unduh asli"
                                                            >
                                                                <Download className="size-3.5" />
                                                            </a>
                                                        </Button>
                                                        {file.deleted_at ? (
                                                            <>
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    className="h-7 px-2 text-xs"
                                                                    onClick={() =>
                                                                        handleRestoreFile(
                                                                            file,
                                                                        )
                                                                    }
                                                                >
                                                                    Pulihkan
                                                                </Button>
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="destructive"
                                                                    className="h-7 px-2 text-xs"
                                                                    onClick={() =>
                                                                        handlePurgeFile(
                                                                            file,
                                                                        )
                                                                    }
                                                                >
                                                                    Purge
                                                                </Button>
                                                            </>
                                                        ) : (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="ghost"
                                                                className="h-7 px-2 text-xs text-destructive hover:text-destructive"
                                                                onClick={() =>
                                                                    handleTrashFile(
                                                                        file,
                                                                    )
                                                                }
                                                                title="Pindah ke Sampah"
                                                            >
                                                                <Trash2 className="size-3.5" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            /* GRID VIEW (Medium / Compact with lightweight lazy thumbnails only) */
                            <div className="space-y-5">
                                {folders.length > 0 && (
                                    <div className="space-y-2">
                                        <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            Folder ({folders.length})
                                        </h2>
                                        <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                                            {folders.map((folder) => (
                                                <button
                                                    key={folder.id}
                                                    type="button"
                                                    onClick={() =>
                                                        openFolder(folder.id)
                                                    }
                                                    className="group flex items-center gap-2.5 rounded-xl border bg-card p-2.5 text-left transition hover:border-primary/50 hover:bg-muted/40"
                                                >
                                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-amber-500/15">
                                                        <FolderIcon className="size-5 text-amber-500 transition-transform group-hover:scale-105" />
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <div className="truncate text-xs font-semibold">
                                                            {folder.name}
                                                        </div>
                                                        <div className="truncate text-[10px] text-muted-foreground tabular-nums">
                                                            {folder.files_count}{' '}
                                                            berkas ·{' '}
                                                            {formatBytes(
                                                                folder.total_bytes,
                                                            )}
                                                        </div>
                                                    </div>
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {files.data.length > 0 && (
                                    <div className="space-y-2">
                                        <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            Berkas ({files.total})
                                        </h2>
                                        <div className={gridClass}>
                                            {files.data.map((file) => (
                                                <div
                                                    key={file.id}
                                                    onClick={() =>
                                                        setPreviewFile(file)
                                                    }
                                                    className="group relative flex cursor-pointer flex-col gap-1.5 rounded-xl border bg-card p-2 transition hover:border-primary/50 hover:shadow-sm"
                                                >
                                                    <LazyFileThumbnail
                                                        file={file}
                                                    />
                                                    <div className="min-w-0 space-y-0.5 px-0.5">
                                                        <div
                                                            className="truncate text-xs font-semibold"
                                                            title={file.name}
                                                        >
                                                            {file.name}
                                                        </div>
                                                        <div className="flex items-center justify-between gap-1 text-[10px] text-muted-foreground tabular-nums">
                                                            <span>
                                                                {formatBytes(
                                                                    file.size_bytes,
                                                                )}
                                                            </span>
                                                            <span className="truncate">
                                                                {
                                                                    file.category_label
                                                                }
                                                            </span>
                                                        </div>
                                                        {filters.view_mode !==
                                                            'explorer' && (
                                                            <div
                                                                className="truncate text-[10px] text-muted-foreground/80"
                                                                title={
                                                                    file.folder_path
                                                                }
                                                            >
                                                                {
                                                                    file.folder_path
                                                                }
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Pagination Footer */}
                        {files.total > 0 && (
                            <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-3 text-xs text-muted-foreground">
                                <span>
                                    Menampilkan {files.from ?? 0}–
                                    {files.to ?? 0} dari{' '}
                                    {files.total.toLocaleString('id-ID')} berkas
                                </span>
                                {files.last_page > 1 && (
                                    <div className="flex items-center gap-1.5">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            className="h-7 rounded-full px-2.5 text-xs"
                                            disabled={files.current_page <= 1}
                                            onClick={() =>
                                                navigateExplorer({
                                                    page:
                                                        files.current_page - 1,
                                                })
                                            }
                                        >
                                            <ChevronLeft className="size-3.5" />
                                            Sebelumnya
                                        </Button>
                                        <span className="px-2 tabular-nums">
                                            Hal {files.current_page} /{' '}
                                            {files.last_page}
                                        </span>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            className="h-7 rounded-full px-2.5 text-xs"
                                            disabled={
                                                files.current_page >=
                                                files.last_page
                                            }
                                            onClick={() =>
                                                navigateExplorer({
                                                    page:
                                                        files.current_page + 1,
                                                })
                                            }
                                        >
                                            Berikutnya
                                            <ChevronRight className="size-3.5" />
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <FilePreviewModal
                file={previewFile}
                files={files.data}
                onClose={() => setPreviewFile(null)}
                onSelectFile={setPreviewFile}
                onTrash={handleTrashFile}
                onPurge={handlePurgeFile}
                onRestore={handleRestoreFile}
            />
        </div>
    );
}

UserStoragePage.layout = {
    breadcrumbs: [
        { title: 'Pengguna & Storage', href: '/users' },
        { title: 'Explorer Storage & File', href: '#' },
    ],
};
