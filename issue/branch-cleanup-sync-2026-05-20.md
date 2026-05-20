# Branch Cleanup dan Sinkronisasi 2026-05-20

## Latar Belakang
Repo lokal memiliki beberapa branch lama, remote branch sisa, dan banyak worktree Codex yang sudah tidak aktif. User meminta branch yang tidak dipakai dibersihkan, branch penting yang belum merge/deploy ditangani, lalu lokal, worktree, repo, dan server disinkronkan.

## Tujuan
- Pastikan tidak ada PR terbuka atau branch penting yang belum masuk `main`.
- Hapus branch lokal/remote yang sudah merged dan tidak dipakai.
- Bersihkan worktree yang clean dan sudah tidak aktif.
- Pastikan `main`, `origin/main`, dan server production berada di commit yang sama.
- Verifikasi status production setelah sinkronisasi.

## Ruang Lingkup
- Audit branch lokal, remote, PR GitHub, dan worktree.
- Hapus hanya branch yang terbukti sudah merged ke `main`.
- Hapus hanya worktree yang statusnya clean.
- Jalankan sinkronisasi server bila server tertinggal dari `origin/main`.
- Smoke check production endpoint.

## Di Luar Scope
- Menghapus backup, log, atau file evaluasi untracked di server production.
- Melakukan refactor atau perubahan fitur aplikasi.
- Merge branch yang belum punya bukti review/PR memadai.

## Area / File Terkait
- Git branch lokal dan remote pada repo `Hasbi1605/ISTA-AI`.
- Worktree lokal di `/Users/macbookair/.codex/worktrees` dan worktree PR lama.
- Server production `root@178.128.103.225:/opt/ista-ai`.

## Risiko
- Menghapus worktree atau branch yang masih punya perubahan lokal dapat menghilangkan pekerjaan yang belum disimpan.
- Menghapus remote branch yang belum merged dapat menghilangkan jalur recovery PR lama.
- Redeploy production yang tidak perlu dapat menimbulkan downtime singkat.

## Langkah Implementasi
1. Fetch remote dengan prune.
2. Cek status branch lokal, remote, PR GitHub, dan worktree.
3. Bandingkan server production dengan `origin/main`.
4. Jika branch/worktree clean dan sudah merged, hapus secara bertahap.
5. Jika remote branch sudah merged dan tidak ada PR open, hapus remote branch.
6. Sinkronkan server hanya bila commit server tertinggal.
7. Jalankan smoke check endpoint production.
8. Pastikan lokal kembali di `main` dan bersih.

## Rencana Test
- `git branch --no-merged main` harus kosong sebelum penghapusan.
- `gh pr list --state open` harus kosong.
- `git status --short --branch` lokal bersih setelah cleanup.
- Server `HEAD` dan `origin/main` sama.
- `curl -I https://ista-ai.app/up` berhasil.

## Kriteria Selesai
- Tidak ada branch lokal non-`main` yang tersisa.
- Tidak ada remote branch stale yang tersisa.
- Worktree lama yang clean sudah dihapus.
- Server production berada di commit terbaru dan service sehat.
- Local `main` tersinkron dengan `origin/main`.
