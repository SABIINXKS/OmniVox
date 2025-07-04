<?php
if (!function_exists('buildCommentTree')) {
    function buildCommentTree($comments, $parent_id = null) {
        $tree = [];
        foreach ($comments as $comment) {
            if ($comment['parent_id'] == $parent_id) {
                $children = buildCommentTree($comments, $comment['comment_id']);
                if ($children) {
                    $comment['children'] = $children;
                }
                $tree[] = $comment;
            }
        }
        return $tree;
    }
}

if (!function_exists('displayComments')) {
    function displayComments($comments, $post_id, $group_id, $user_id, $level = 0) {
        foreach ($comments as $comment):
        ?>
            <div class="comment <?php echo $comment['parent_id'] ? 'reply' : ''; ?>" data-comment-id="<?php echo $comment['comment_id']; ?>">
                <div class="profile-photo">
                    <img src="<?php echo htmlspecialchars($comment['profile_picture'] ?? './profile_pics/profile.jpg'); ?>" alt="Profile">
                </div>
                <div class="comment-body">
                    <h6><?php echo htmlspecialchars($comment['username']); ?></h6>
                    <?php if ($comment['parent_id'] && $comment['parent_username']): ?>
                        <div class="reply-to">Replying to @<?php echo htmlspecialchars($comment['parent_username']); ?></div>
                    <?php endif; ?>
                    <p><?php echo htmlspecialchars($comment['content']); ?></p>
                    <small class="text-muted"><?php echo date('M d, Y H:i', strtotime($comment['created_at'])); ?></small>
                    <button class="reply-btn" data-comment-id="<?php echo $comment['comment_id']; ?>">Reply</button>
                    <?php if ($comment['user_id'] == $user_id): ?>
                        <button class="delete-btn" data-comment-id="<?php echo $comment['comment_id']; ?>" data-post-id="<?php echo $post_id; ?>">Delete</button>
                    <?php endif; ?>
                    <form class="comment-form reply-form" id="reply-form-<?php echo $comment['comment_id']; ?>" data-post-id="<?php echo $post_id; ?>">
                        <input type="hidden" name="add_comment" value="1">
                        <input type="hidden" name="post_id" value="<?php echo $post_id; ?>">
                        <input type="hidden" name="parent_id" value="<?php echo $comment['comment_id']; ?>">
                        <textarea name="comment_content" placeholder="Type your reply..." rows="2" required></textarea>
                        <button type="submit">Post</button>
                    </form>
                </div>
            </div>
            <?php
            if (isset($comment['children'])) {
                displayComments($comment['children'], $post_id, $group_id, $user_id, $level + 1);
            }
            endforeach;
        }
}
?>