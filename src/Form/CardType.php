<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Board;
use App\Entity\BoardColumn;
use App\Entity\Card;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class CardType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'empty_data' => '',
                'attr' => [
                    'autocomplete' => 'off',
                    'maxlength' => 255,
                ],
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 255),
                ],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'attr' => [
                    'rows' => 5,
                ],
            ]);

        if ($options['include_column']) {
            $builder->add('column', EntityType::class, [
                'class' => BoardColumn::class,
                'choice_label' => 'name',
                'query_builder' => static fn (EntityRepository $repository) => $repository
                    ->createQueryBuilder('board_column')
                    ->andWhere('board_column.board = :board')
                    ->setParameter('board', $options['board'])
                    ->orderBy('board_column.position', 'ASC'),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Card::class,
            'include_column' => false,
        ]);
        $resolver->setRequired('board');
        $resolver->setAllowedTypes('board', Board::class);
        $resolver->setAllowedTypes('include_column', 'bool');
    }
}
