<?php

namespace App\CMS;

use App\ValueObjects\ItemMetadata;

/**
 * Refactored CMS Item class with clean separation of concerns
 */
class Item
{
    private array $relations = [];

    public function __construct(
        private ItemMetadata $metadata,
        private string $body,
        private string $htmlBody
    ) {}

    // Metadata accessors
    public function slug(): string
    {
        return $this->metadata->slug;
    }
    public function title(): string
    {
        return $this->metadata->title;
    }
    public function author(): mixed
    {
        // Check if we have enriched authors available
        $enrichedAuthors = $this->getEnrichedAuthors();

        if (!empty($enrichedAuthors)) {
            $firstAuthor = $enrichedAuthors[0];

            // If it's an enriched author (array), return it
            if (is_array($firstAuthor)) {
                return $firstAuthor;
            }

            // If it's a string, return it
            return $firstAuthor;
        }

        // Fallback to original metadata author for backward compatibility
        return $this->metadata->author;
    }

    /**
     * Get author name as string (for backward compatibility)
     */
    public function authorName(): ?string
    {
        $author = $this->author();

        if (is_array($author)) {
            return $author['name'] ?? null;
        }

        return $author;
    }
    public function featured(): bool
    {
        return $this->metadata->featured;
    }
    public function status(): string
    {
        return $this->metadata->status;
    }
    public function tags(): array
    {
        return $this->metadata->tags;
    }

    /**
     * Get the first tag (enriched Tag object if available, otherwise string)
     * For backward compatibility and convenience
     */
    public function tag(): mixed
    {
        $enrichedTags = $this->getEnrichedTags();

        if (!empty($enrichedTags)) {
            return $enrichedTags[0];
        }

        // Fallback to simple string tag
        $tags = $this->tags();
        return !empty($tags) ? $tags[0] : null;
    }

    /**
     * Get tag name as string for backward compatibility
     */
    public function tagName(): ?string
    {
        $tag = $this->tag();

        if (is_array($tag)) {
            return $tag['name'] ?? null;
        }

        return is_string($tag) ? $tag : null;
    }

    /**
     * Get enriched tags (Tag objects if available, otherwise strings)
     */
    public function getEnrichedTags(): array
    {
        $tags = $this->metadata->tags;

        // If tags are already enriched (arrays with metadata), return them as-is
        if (!empty($tags) && is_array($tags[0] ?? null)) {
            return $tags;
        }

        // Otherwise return simple string tags
        return $tags;
    }

    /**
     * Get enriched authors (Author objects if available, otherwise strings)
     */
    public function getEnrichedAuthors(): array
    {
        // Check if we have enriched authors stored in relations
        $enrichedAuthors = $this->relation('enriched_authors');
        if ($enrichedAuthors !== null) {
            return $enrichedAuthors;
        }

        // Fallback to simple author string from metadata (avoid circular dependency)
        $author = $this->metadata->author;
        if ($author) {
            return [$author];
        }

        return [];
    }
    public function categories(): array
    {
        return $this->metadata->categories;
    }
    public function image(): ?array
    {
        $imageData = $this->metadata->image;

        if (!$imageData || !is_array($imageData)) {
            return null;
        }

        // Return consistent 6-field structure with empty strings for missing fields
        return [
            'src' => $imageData['src'] ?? '',
            'type' => $imageData['type'] ?? '',
            'title' => $imageData['title'] ?? '',
            'width' => $imageData['width'] ?? '',
            'height' => $imageData['height'] ?? '',
            'alt' => $imageData['alt'] ?? '',
        ];
    }
    public function excerpt(): ?string
    {
        return $this->metadata->excerpt;
    }

    // Content accessors
    public function body(): string
    {
        return $this->htmlBody;
    }
    public function rawBody(): string
    {
        return $this->body;
    }

    // Date methods
    public function date(string $format = 'F j, Y'): string
    {
        return $this->metadata->formatDate($format);
    }

    public function datePublished(string $format = 'F j, Y'): string
    {
        return $this->date($format);
    }

    public function dateModified(string $format = 'F j, Y'): string
    {
        return $this->metadata->formatDate($format);
    }

    // URL method for compatibility
    public function url(): ?string
    {
        return $this->meta('url');
    }

    // Custom metadata
    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->metadata->getCustom($key, $default);
    }

    // File information (for compatibility)
    public function file(): ?string
    {
        return $this->meta('filepath');
    }

    public function fileName(): ?string
    {
        $filepath = $this->file();
        return $filepath ? basename($filepath) : null;
    }



    // Relations
    public function attach(string $key, mixed $value): void
    {
        $this->relations[$key] = $value;
    }

    public function relation(string $key): mixed
    {
        return $this->relations[$key] ?? null;
    }

    public function getRelations(): array
    {
        return $this->relations;
    }

    // Status checks
    public function isPublished(): bool
    {
        return $this->metadata->isPublished();
    }

    public function isDraft(): bool
    {
        return $this->metadata->isDraft();
    }

    // Metadata access
    public function getMetadata(): ItemMetadata
    {
        return $this->metadata;
    }

    // Serialization
    public function toArray(array $context = []): array
    {
        $standardFields = [
            'slug' => $this->slug(),
            'url' => $this->url(),
            'title' => $this->title(),
            'date' => $this->date('c'),
            'date_published' => $this->datePublished('c'),
            'date_modified' => $this->dateModified('c'),
            'author' => $this->author(),
            'author_name' => $this->authorName(),
            'authors' => $this->getEnrichedAuthors(),
            'tag' => $this->tag(),
            'tag_name' => $this->tagName(),
            'tags' => $this->getEnrichedTags(),
            'featured' => $this->featured(),
            'status' => $this->status(),
            'categories' => $this->categories(),
            'image' => $this->image(),
            'excerpt' => $this->excerpt(),
            'body' => $this->body(),
            'raw_body' => $this->rawBody(),
            'meta' => $this->metadata->custom,
            'relations' => $this->relations,
            'filepath' => $this->file(),
            'collection_path' => $context['collection_path'] ?? null,
        ];

        // Flatten custom frontmatter fields to make them directly accessible (e.g., item.slogan)
        // Handle nested structure: metadata->custom may contain a 'custom' key with the actual fields
        $allCustomFields = $this->metadata->custom ?? [];

        // If there's a nested 'custom' key, get the fields from inside it
        if (isset($allCustomFields['custom']) && is_array($allCustomFields['custom'])) {
            $customFields = $allCustomFields['custom'];
        } else {
            $customFields = $allCustomFields;
        }

        // Flatten each custom field to top level
        foreach ($customFields as $key => $value) {
            if (!array_key_exists($key, $standardFields) && $key !== 'filepath') {
                $standardFields[$key] = $value;
            }
        }

        return $standardFields;
    }

    // JSON serialization
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // String representation
    public function __toString(): string
    {
        return $this->title();
    }

    // Magic method for dynamic property access
    public function __get(string $name): mixed
    {
        // Try metadata first
        if (property_exists($this->metadata, $name)) {
            return $this->metadata->$name;
        }

        // Try relations
        if (isset($this->relations[$name])) {
            return $this->relations[$name];
        }

        // Try custom metadata
        return $this->meta($name);
    }

    // Check if property exists
    public function __isset(string $name): bool
    {
        return property_exists($this->metadata, $name) ||
            isset($this->relations[$name]) ||
            $this->metadata->hasCustom($name);
    }
}
