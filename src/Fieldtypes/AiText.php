<?php

namespace BoldWeb\StatamicAiAssistant\Fieldtypes;

use Statamic\Fields\Fieldtype;

class AiText extends Fieldtype
{
  protected $icon = '<svg viewBox="0 0 16 16">
  <path d="M11.7,5v6M9,2.3c1.5,0,2.7,1.2,2.7,2.7,0-1.5,1.2-2.7,2.7-2.7M9,13.7c1.5,0,2.7-1.2,2.7-2.7,0,1.5,1.2,2.7,2.7,2.7" style="fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: .7px;"/>
  <path d="M9.3,5h5.7c.4,0,.7.3.7.7v4.7c0,.4-.3.7-.7.7H1c-.4,0-.7-.3-.7-.7v-4.7c0-.4.3-.7.7-.7h.6" style="fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: .7px;"/>
  <path d="M5.1,3.9c.1-.3.5-.3.7,0l.5,1.3c0,0,.1.1.2.2l1.3.5c.3.1.3.5,0,.7l-1.3.5c0,0-.1,0-.2.1l-.5,1.3c-.1.3-.5.3-.7,0l-.5-1.3c0,0-.1-.1-.2-.2l-1.3-.5c-.3-.1-.3-.5,0-.7l1.3-.5c0,0,.1,0,.2-.1l.5-1.3Z" style="fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: .7px;"/>
</svg>';
  public $categories = ['text'];
  protected $keywords = ['ai', 'ia', 'bold', 'text'];
}
