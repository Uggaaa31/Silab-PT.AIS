// ============================================================
//  assets/js/main.js — LabMineral Pro
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

    // ── Auto-hide pesan sukses setelah 4 detik ────────────────
    document.querySelectorAll('.alert-box.alert-green').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .5s';
            el.style.opacity    = '0';
            setTimeout(function () { el.remove(); }, 500);
        }, 4000);
    });

    // ── Konfirmasi hapus ──────────────────────────────────────
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(this.dataset.confirm)) e.preventDefault();
        });
    });

    // ── Highlight baris tabel aktif saat diklik ───────────────
    document.querySelectorAll('table tbody tr').forEach(function (row) {
        row.addEventListener('click', function () {
            document.querySelectorAll('table tbody tr.selected')
                    .forEach(function (r) { r.classList.remove('selected'); });
            this.classList.add('selected');
        });
    });

});
