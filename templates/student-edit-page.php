<?php

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>

<div class="container academic-route-page academic-profile-edit-page" id="academic-route-container">
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

<?php get_footer(); ?>
