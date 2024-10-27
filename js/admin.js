jQuery(document).ready(function($) {
    $('#run-ai-article-writer').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $result = $('#ai-article-writer-result');
        
        $button.prop('disabled', true).text('Generating...');
        $result.text('');
        
        $.ajax({
            url: aiArticleWriter.ajax_url,
            type: 'POST',
            data: {
                action: 'run_ai_article_writer',
                nonce: aiArticleWriter.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<p style="color: green;">' + response.data + '</p>');
                } else {
                    $result.html('<p style="color: red;">' + response.data + '</p>');
                    if (response.data.includes('All topics have been used')) {
                        $('#all-topics-used-warning').show();
                    }
                }
            },
            error: function() {
                $result.html('<p style="color: red;">An error occurred. Please try again.</p>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Generate Article Now');
            }
        });
    });

    // Add event listener for cron_enabled checkbox
    $('#cron_enabled').on('change', function() {
        if (this.checked) {
            alert('Automatic posting has been enabled. Articles will be generated based on your "Posts Per Day" setting.');
        } else {
            alert('Automatic posting has been disabled. You can still generate articles manually using the "Generate Article Now" button.');
        }
    });

    // Add event listener for posts_per_day input
    $('#posts_per_day').on('change', function() {
        var postsPerDay = $(this).val();
        if (postsPerDay < 1) {
            $(this).val(1);
            alert('The minimum number of posts per day is 1.');
        } else if (postsPerDay > 24) {
            $(this).val(24);
            alert('The maximum number of posts per day is 24.');
        }
    });

    // Add event listener for reset_topics checkbox
    $('#reset_topics').on('change', function() {
        if (this.checked) {
            if (confirm('Are you sure you want to reset the used topics list? This will allow all topics to be used again and restart the automatic posting if enabled.')) {
                $('#all-topics-used-warning').hide();
            } else {
                this.checked = false;
            }
        }
    });
});