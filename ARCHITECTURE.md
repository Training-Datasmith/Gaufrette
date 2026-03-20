# Gaufrette Architecture

## Purpose

A filesystem abstraction layer for PHP that provides a uniform API over many
storage backends: local disk, Amazon S3, Azure Blob Storage, Google Cloud
Storage, SFTP, FTP, GridFS, Doctrine DBAL, and more.

## Directory Structure

```
src/Gaufrette/
  Adapter.php                   — interface all adapters must implement
  Adapter/
    Local.php                   — local filesystem
    Safe_Local.php              — local filesystem with stricter error handling
    In_Memory.php               — in-process memory (great for tests)
    Aws_S3.php / Async_Aws_S3.php
    Azure_Blob_Storage.php
    Google_Cloud_Storage.php
    Ftp.php / Phpseclib_Sftp.php
    Doctrine_Dbal.php
    Flysystem.php               — delegates to Flysystem v1
    Grid_FS.php                 — MongoDB GridFS
    Zip.php
    Checksum_Calculator.php     — optional: compute checksums
    Metadata_Supporter.php      — optional: read/write metadata
    Mime_Type_Provider.php      — optional: return MIME type
    Size_Calculator.php         — optional: return file size
    Stream_Factory.php          — optional: return a Stream for the file
  Exception/
    File_Already_Exists.php
    File_Not_Found.php
    Unexpected_File.php
    Unsupported_Adapter_Method_Exception.php
  File.php                      — lazy wrapper around a single file
  Filesystem.php                — main API: read, write, delete, exists, keys
  Filesystem_Interface.php
  Filesystem_Map.php            — registry of named Filesystem instances
  Filesystem_Map_Interface.php
  Stream.php                    — interface for readable/writable streams
  Stream/
    In_Memory_Buffer.php
    Local.php
  Stream_Mode.php               — parses fopen-style mode strings
  Stream_Wrapper.php            — registers a stream wrapper (gaufrette://)
  Util/
    Checksum.php / Path.php / Size.php
```

## Key Design Decisions

- **Adapter pattern**: `Filesystem` delegates all I/O to an injected `Adapter`;
  switching backends requires only changing the adapter in the DI container.
- **Optional adapter capabilities**: extra interfaces (`Metadata_Supporter`,
  `Mime_Type_Provider`, `Size_Calculator`, `Checksum_Calculator`,
  `Stream_Factory`, `List_Keys_Aware`) are implemented only by adapters that
  support those features, with graceful fallbacks in `Filesystem`.
- **`gaufrette://` stream wrapper**: `Stream_Wrapper` registers a PHP stream
  wrapper so that Gaufrette filesystems can be used with native PHP file
  functions and SPL iterators.
- **`Filesystem_Map`**: allows bundles/frameworks to register multiple named
  filesystems and resolve them by name at runtime.

## Extension Points

- Implement `Adapter` (and optionally `Stream_Factory`, `Metadata_Supporter`,
  etc.) to add a new storage backend.
- Register a `Filesystem` instance with `Filesystem_Map` to make it accessible
  system-wide by name.
- Use `Stream_Wrapper::register()` to expose a filesystem via `gaufrette://name/`.

## Dependency Flow

```
Consumer
  └── Filesystem (read / write / delete / exists / keys)
        └── Adapter (Local, S3, Azure, GCS, SFTP, …)
              └── Storage SDK / PHP ext
```
