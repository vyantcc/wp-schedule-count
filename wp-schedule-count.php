<?php
/**
 * Plugin Name: WP Schedule Count
 * Description: Menampilkan jumlah artikel publish berdasarkan schedule post per hari (summary, tabel kecil Bootstrap dengan badge warna, pagination, filter kategori, dan export CSV).
 * Version: 1.5
 * Author: Vyant
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPScheduleCount {
    private $per_page = 30; // jumlah hari per halaman

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'handle_export_csv']);
    }

    public function register_menu() {
        add_menu_page(
            'Schedule Count',
            'Schedule Report',
            'manage_options',
            'wp-schedule-count',
            [$this, 'render_page'],
            'dashicons-calendar-alt',
            25
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_wp-schedule-count') return;

        wp_enqueue_script('jquery');
        wp_enqueue_style('bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');
        wp_enqueue_script('bootstrap-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', ['jquery'], null, true);
        wp_enqueue_style('schedule-report-css', plugin_dir_url(__FILE__) . 'assets/style.css');
    }

    public function handle_export_csv() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'wp-schedule-count') return;
        if (!isset($_GET['export']) || $_GET['export'] !== 'csv') return;

        global $wpdb;

        $selected_cat = isset($_GET['cat']) ? intval($_GET['cat']) : 0;
        $join_clause  = '';
        $where_clause = "WHERE p.post_status = 'future' AND p.post_type = 'post'";

        if ($selected_cat) {
            $join_clause  = "INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            $where_clause .= $wpdb->prepare(" AND tt.term_id = %d", $selected_cat);
        }

        // Ambil semua data (tanpa LIMIT)
        $results = $wpdb->get_results("
            SELECT DATE(p.post_date) as publish_date, COUNT(p.ID) as total_posts
            FROM {$wpdb->posts} p
            $join_clause
            $where_clause
            GROUP BY DATE(p.post_date)
            ORDER BY publish_date ASC
        ");

        // Nama file hanya domain + tanggal
        $domain   = preg_replace('/^www\./', '', $_SERVER['SERVER_NAME']);
        $date     = date('Y-m-d');
        $filename = $domain . '-' . $date . '.csv';

        // Header CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Tanggal Publish', 'Jumlah Artikel']);

        foreach ($results as $row) {
            fputcsv($output, [
                date_i18n(get_option('date_format'), strtotime($row->publish_date)),
                $row->total_posts
            ]);
        }

        fclose($output);
        exit;
    }

    public function render_page() {
        global $wpdb;

        // Ambil kategori untuk dropdown
        $categories = get_categories(['hide_empty' => false]);

        $selected_cat = isset($_GET['cat']) ? intval($_GET['cat']) : 0;

        $join_clause  = '';
        $where_clause = "WHERE p.post_status = 'future' AND p.post_type = 'post'";

        if ($selected_cat) {
            $join_clause  = "INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id";
            $where_clause .= $wpdb->prepare(" AND tt.term_id = %d", $selected_cat);
        }

        $total_days = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT DATE(p.post_date))
            FROM {$wpdb->posts} p
            $join_clause
            $where_clause
        ");

        $total_articles = (int) $wpdb->get_var("
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            $join_clause
            $where_clause
        ");

        // Pagination
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset       = ($current_page - 1) * $this->per_page;

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT DATE(p.post_date) as publish_date, COUNT(p.ID) as total_posts
            FROM {$wpdb->posts} p
            $join_clause
            $where_clause
            GROUP BY DATE(p.post_date)
            ORDER BY publish_date ASC
            LIMIT %d OFFSET %d
        ", $this->per_page, $offset));

        echo '<div class="wrap container mt-4">';
        echo '<h1 class="mb-4">Schedule Post Report</h1>';

        // Filter kategori + tombol export
        echo '<form method="get" class="mb-3 d-flex align-items-center">';
        echo '<input type="hidden" name="page" value="wp-schedule-count">';
        echo '<label for="cat" class="form-label me-2"><strong>Filter Kategori:</strong></label>';
        echo '<select name="cat" id="cat" class="form-select d-inline-block w-auto me-2">';
        echo '<option value="0">Semua Kategori</option>';
        foreach ($categories as $cat) {
            $selected = $selected_cat === $cat->term_id ? 'selected' : '';
            echo '<option value="' . esc_attr($cat->term_id) . '" ' . $selected . '>' . esc_html($cat->name) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" class="btn btn-primary me-2">Terapkan</button>';
        echo '<a href="' . esc_url(add_query_arg(['page' => 'wp-schedule-count', 'cat' => $selected_cat, 'export' => 'csv'], admin_url('admin.php'))) . '" class="btn btn-success">Export CSV</a>';
        echo '</form>';

        // Summary
        echo '<div class="alert alert-info d-flex justify-content-between align-items-center">';
        echo '<div><strong>Total Artikel Terjadwal:</strong> ' . esc_html($total_articles) . '</div>';
        echo '<div><strong>Total Hari:</strong> ' . esc_html($total_days) . '</div>';
        echo '</div>';

        // Tabel
        echo '<table class="table table-sm table-bordered table-striped align-middle">';
        echo '<thead class="table-dark"><tr><th>Tanggal Publish</th><th>Jumlah Artikel</th></tr></thead>';
        echo '<tbody>';

        if ($results) {
            foreach ($results as $row) {
                $count = (int) $row->total_posts;

                if ($count < 3) {
                    $row_class   = 'table-danger';
                    $badge_class = 'bg-danger';
                } elseif ($count < 5) {
                    $row_class   = 'table-warning';
                    $badge_class = 'bg-warning text-dark';
                } else {
                    $row_class   = 'table-success';
                    $badge_class = 'bg-success';
                }

                echo '<tr class="' . esc_attr($row_class) . '">';
                echo '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime($row->publish_date))) . '</td>';
                echo '<td><span class="badge ' . esc_attr($badge_class) . ' fs-6">' . esc_html($count) . '</span></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="2" class="text-center">Tidak ada schedule post.</td></tr>';
        }

        echo '</tbody></table>';

        // Pagination
        $total_pages = ceil($total_days / $this->per_page);
        if ($total_pages > 1) {
            $base_url = add_query_arg([
                'page' => 'wp-schedule-count',
                'cat'  => $selected_cat,
                'paged' => '%#%'
            ], admin_url('admin.php'));

            $links = paginate_links([
                'base'      => $base_url,
                'format'    => '',
                'current'   => $current_page,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'type'      => 'array'
            ]);

            if ($links) {
                echo '<nav><ul class="pagination">';
                foreach ($links as $link) {
                    $active = strpos($link, 'current') !== false ? ' active' : '';
                    echo '<li class="page-item' . $active . '">' . str_replace('page-numbers', 'page-link', $link) . '</li>';
                }
                echo '</ul></nav>';
            }
        }

        echo '</div>';
    }
}

new WPScheduleCount();
