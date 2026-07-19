<?php
/**
 * Full-width wrapper for virtual PI/student profile routes.
 *
 * This bypasses the active theme's default page.php sidebar while still using
 * the theme header, footer, and normal content hooks.
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>

<?php echo AcademicDirectory::render_virtual_profile_hero(); ?>

<div class="container academic-route-page" id="academic-route-container">
    <div id="primary" class="content-area">
        <main id="main" class="site-main" role="main">
            <?php while (have_posts()) : the_post(); ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class('academic-route-article'); ?>>
                    <div class="entry-content">
                        <?php the_content(); ?>
                    </div>
                </article>
            <?php endwhile; ?>
        </main>
    </div>
</div>

<?php
get_footer();
