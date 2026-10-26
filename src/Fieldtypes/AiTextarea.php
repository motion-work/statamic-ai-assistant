<?php

namespace BoldWeb\StatamicAiAssistant\Fieldtypes;

use Statamic\Fields\Fieldtype;

class AiTextarea extends Fieldtype
{
  protected $icon = '<svg version="1.1"
	 viewBox="0 0 16.2 16.7" style="enable-background:new 0 0 16.2 16.7;" xml:space="preserve">
  <g transform="scale(.66667)">
      <path style="fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-miterlimit:2.6667;" d="
      M1.6,1.1h21c0.6,0,1,0.4,1,1v21c0,0.6-0.4,1-1,1h-21c-0.6,0-1-0.4-1-1v-21C0.6,1.5,1.1,1.1,1.6,1.1z"/>
    
      <path style="fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-miterlimit:2.6667;" d="
      M13.4,5.6h6.2 M13.4,10.1h6.2 M4.1,14.6h15.5 M4.1,19.1h15.5"/>
    
      <path style="fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-miterlimit:2.6667;" d="
      M6.7,3.9c0.2-0.4,0.8-0.4,1,0l0.8,2C8.6,6,8.7,6.1,8.8,6.2l2,0.8c0.4,0.2,0.4,0.8,0,1l-2,0.8C8.7,8.8,8.6,8.9,8.5,9l-0.8,2
      c-0.2,0.4-0.8,0.4-1,0L6,9C5.9,8.9,5.8,8.8,5.7,8.7l-2-0.8c-0.4-0.2-0.4-0.8,0-1l2-0.8C5.8,6.1,5.9,6,6,5.9L6.7,3.9z"/>
  </g>
</svg>
';
  public $categories = ['text'];
  protected $keywords = ['ai', 'ia', 'bold', 'text','textarea'];
}
