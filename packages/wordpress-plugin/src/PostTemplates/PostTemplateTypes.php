<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\PostTemplates;

final class PostTemplateTypes
{
    public const POST_TYPE = 'mcp_post_template';

    public const META_FOR_TYPE = '_mcp_template_for_type';

    public const META_IS_DEFAULT = '_mcp_template_is_default';

    public const META_MATCH_CATEGORY_SLUGS = '_mcp_template_match_category_slugs';

    public const META_MATCH_TITLE_KEYWORDS = '_mcp_template_match_title_keywords';

    public const META_DEFAULT_CATEGORIES = '_mcp_template_default_categories';

    public const META_DEFAULT_TAGS = '_mcp_template_default_tags';

    /** @var list<string> */
    public const PLACEHOLDERS = ['title', 'excerpt', 'content', 'body'];

    /** @var list<string> */
    public const ACTIVE_STATUSES = ['publish', 'draft', 'private'];
}
